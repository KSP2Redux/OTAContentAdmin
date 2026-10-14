"""Regression checks for prefab selection, inherited units and helper meshes."""
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
import yaml

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location("exporter", SCRIPTS / "export-vessel-models.py")
exporter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(exporter)


def prefab(path, nodes, part_core=False):
    documents = []
    for index, (name, parent, scale, rendered) in enumerate(nodes, 1):
        documents += [(1, index, {"GameObject": {"m_Name": name, "m_IsActive": 1}}),
                      (4, index+100, {"Transform": {"m_GameObject": {"fileID": index}, "m_Father": {"fileID": parent+100 if parent else 0},
                         "m_LocalScale": dict.fromkeys("xyz", scale)}})]
        if rendered:
            documents += [(23, index+200, {"MeshRenderer": {"m_GameObject": {"fileID": index}, "m_Enabled": 1}}),
                          (33, index+300, {"MeshFilter": {"m_GameObject": {"fileID": index}, "m_Mesh": {"guid": "triangle"}}})]
    if part_core:
        documents.append((114, 500, {"MonoBehaviour": {"m_GameObject": {"fileID": 1}, "core": {"data": {"partName": "strut"}}}}))
    path.write_text("".join(f"--- !u!{kind} &{ident}\n"+yaml.safe_dump(data) for kind, ident, data in documents), encoding="utf-8")


class VesselExporterTests(unittest.TestCase):
    def test_part_prefab_wins_over_unsuffixed_model_leaf(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            prefab(root / "strut.prefab", [("strut", 0, 1, True)])
            prefab(root / "strut_0.prefab", [("strut", 0, 1, False), ("connector", 1, .01, True)], part_core=True)
            self.assertEqual(exporter.select_prefab(root, "strut"), root / "strut_0.prefab")
            self.assertIsNone(exporter.select_prefab(root, "unavailable"))

    def test_baked_geometry_keeps_centimeter_scale_and_excludes_helper_ancestors(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "strut.prefab"
            prefab(source, [("strut", 0, 1, False), ("connector", 1, .01, True),
                           ("collision", 1, 1, False), ("body", 3, 1, True),
                           ("Base_col", 1, 1, True), ("heat_reentry", 1, 1, True)])
            triangle = (np.array([[0., 0., 0.], [10., 0., 0.], [0., 10., 0.]]), np.array([0, 1, 2]), None, None, [])
            with patch.object(exporter, "mesh", return_value=triangle):
                positions, indices, _, warnings = exporter.bake(source, {"triangle": Path(directory) / "mesh.asset"}, "extended")
            self.assertEqual(len(positions), 3)
            self.assertEqual(len(indices), 3)
            np.testing.assert_allclose(positions.max(axis=0), [.1, .1, 0])
            self.assertEqual(warnings, [])

    def test_collision_names_without_hiding_visible_part_names(self):
        from preview_geometry import is_helper
        for name in ["col_base", "Base_col", "Collider.001", "body_collision", "Panel.1_reentry", "collision01"]:
            self.assertTrue(is_helper(name), name)
        for name in ["collector_1v_radial", "Center Column", "shared_colony_hatch", "solararray_3v_large"]:
            self.assertFalse(is_helper(name), name)

    def test_numeric_hex_buffers_are_not_interpreted_as_yaml_numbers(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory)/"mesh.asset"
            source.write_text('--- !u!43 &1\nMesh:\n  m_IndexBuffer: 000001000200\n  m_VertexData:\n    _typelessdata: 000000000000\n', encoding='utf-8')
            data = exporter.unity(source)[1][1]
            self.assertEqual(data['m_IndexBuffer'], '000001000200')
            self.assertEqual(data['m_VertexData']['_typelessdata'], '000000000000')


if __name__ == "__main__":
    unittest.main()
