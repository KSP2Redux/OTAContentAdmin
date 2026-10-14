"""Export private procedural rigs and definition defaults without executing Unity."""
from functools import lru_cache
from pathlib import Path
import math
import numpy as np
from preview_geometry import is_helper


def vector(value, keys="xyz", default=0):
    return [float((value or {}).get(k, default)) for k in keys]


def modules(definition, name):
    return [m.get("DataObject", {}) for p in definition.get("serializedPartModules", [])
            for m in p.get("ModuleData", []) if m.get("Name") == name]


def model_matrix(docs, exporter):
    transforms = {i: d for i, (t, d) in docs.items() if t == 4}
    games = {i: d for i, (t, d) in docs.items() if t == 1}
    @lru_cache(None)
    def world(i):
        d = dict(transforms[i])
        parent = d["m_Father"]["fileID"]
        if parent not in transforms:
            d["m_LocalPosition"] = dict.fromkeys("xyz", 0)
            d["m_LocalRotation"] = dict(zip("xyzw", [0, 0, 0, 1]))
        matrix = exporter.transform(d)
        return world(parent) @ matrix if parent in transforms else matrix
    node = next((i for i, d in transforms.items() if games[d["m_GameObject"]["fileID"]]["m_Name"] == "model"), None)
    return world(node) if node is not None else np.eye(4)


def rig(path, guids, exporter):
    docs = exporter.expand_prefab(path, guids)
    transforms = {i: d for i, (t, d) in docs.items() if t == 4}
    games = {i: d for i, (t, d) in docs.items() if t == 1}
    game_transforms = {d["m_GameObject"]["fileID"]: i for i, d in transforms.items()}
    nodes = [{"id": str(i), "name": games[d["m_GameObject"]["fileID"]]["m_Name"],
              "parent": str(d["m_Father"]["fileID"]), "position": vector(d.get("m_LocalPosition")),
              "rotation": vector(d.get("m_LocalRotation"), "xyzw"), "scale": vector(d.get("m_LocalScale"), default=1)}
             for i, d in transforms.items()]
    sliders, constraints, meshes = [], [], []
    managed = {}
    for kind, data in docs.values():
        if kind == 114 and "NamedMeshBonePairs" in data:
            for group in data["NamedMeshBonePairs"]:
                for ref in group.get("VisualSkinnedMeshes", []) + group.get("VisualStaticMeshes", []):
                    managed[ref["fileID"]] = (group["Name"], str(group.get("TargetAttachTransform", {}).get("fileID", 0)))
        if kind == 114 and "Sliders" in data:
            for slider in data["Sliders"]:
                targets = [{"node": str(t["target"]["fileID"]), "mode": t["mode"],
                            "min": vector(t["MinV3"]), "max": vector(t["MaxV3"])} for t in slider["targets"]]
                neutral = 0
                if targets:
                    lo, hi = np.array(targets[0]["min"]), np.array(targets[0]["max"])
                    denominator = float(np.dot(hi-lo, hi-lo))
                    neutral = math.sqrt(float(np.dot(lo, lo))/denominator) if denominator else 0
                limits = [{"parent": d["ParentSliderName"], "curve": d["LimitCurve"].get("m_Curve", [])}
                          for d in slider.get("dynamicLimits", [])]
                sliders.append({"name": slider["Name"], "default": slider.get("startValue", 0),
                                "neutral": neutral, "limits": limits, "targets": targets})
        if kind in (1818360608, 1818360610, 1183024399, 895512359) and data.get("m_Enabled", 1):
            # OffsetSliderSystem.Initialize activates even authored inactive constraints.
            targets = [{"node": str(s["sourceTransform"]["fileID"]), "weight": float(s["weight"])} for s in data.get("m_Sources", [])]
            if not targets: continue
            mode = {1818360608: "position", 1818360610: "scale", 1183024399: "look", 895512359: "look"}[kind]
            field = "Translation" if mode == "position" else "Scale"
            constraints.append({"node": str(game_transforms[data["m_GameObject"]["fileID"]]), "mode": mode,
                                "sources": targets, "offset": vector(data.get("m_"+field+"Offset"), default=1 if mode == "scale" else 0),
                                "axes": [bool(data.get("m_Affect"+("Scaling" if mode == "scale" else "Translation")+a, 1)) for a in "XYZ"],
                                "up": str(data.get("m_WorldUpObject", {}).get("fileID", 0)), "useUp": bool(data.get("m_UseUpObject", 0)),
                                "upMode": "rotation" if kind == 895512359 and data.get("m_UpType") == 2 else "position",
                                "upVector": vector(data.get("m_WorldUpVector", {"x": 0, "y": 1, "z": 0})),
                                "roll": float(data.get("m_Roll", 0)), "weight": float(data.get("m_Weight", 1))})
            if kind == 895512359: constraints[-1]["useUp"] = bool(data.get("m_WorldUpObject", {}).get("fileID"))
    for ident, (kind, renderer) in docs.items():
        if kind not in (23, 137) or ident not in managed: continue
        tid = game_transforms[renderer["m_GameObject"]["fileID"]]
        ancestor, excluded = tid, False
        while ancestor in transforms:
            if is_helper(games[transforms[ancestor]["m_GameObject"]["fileID"]]["m_Name"]): excluded = True; break
            ancestor = transforms[ancestor]["m_Father"]["fileID"]
        if excluded: continue
        filters = [d for t, d in docs.values() if t == 33 and d["m_GameObject"]["fileID"] == renderer["m_GameObject"]["fileID"]]
        ref = renderer.get("m_Mesh", {}) if kind == 137 else filters[0].get("m_Mesh", {}) if filters else {}
        source = guids.get(ref.get("guid"))
        if not source: continue
        positions, indices, weights, joints, binds = exporter.mesh(str(source))
        if joints is not None and weights is not None and weights.shape[1] < joints.shape[1]:
            weights = np.column_stack([weights, 1-weights.sum(axis=1)])
        name, attach = managed[ident]
        meshes.append({"name": name, "node": str(tid), "attach": attach,
                       "positions": positions.reshape(-1).tolist(), "indices": indices.tolist(),
                       "bones": [str(b["fileID"]) for b in renderer.get("m_Bones", [])],
                       "weights": weights.tolist() if weights is not None else None,
                       "joints": joints.tolist() if joints is not None else None,
                       "binds": [m.T.reshape(-1).tolist() for m in binds]})
    if not meshes: raise ValueError("No managed procedural visual meshes")
    return {"nodes": nodes, "sliders": sliders, "constraints": constraints, "meshes": meshes}


def export_part(assets, guids, definition, exporter):
    fairings = modules(definition, "Data_Fairing")
    procedural = modules(definition, "Data_ProceduralPart")
    if not fairings and not procedural: return None
    name = definition["partName"]
    address = Path(definition.get("_previewPrefab") or name).stem
    prefab = exporter.select_prefab(assets, address)
    docs = exporter.expand_prefab(prefab, guids) if prefab else {}
    model = model_matrix(docs, exporter)
    result = {"version": 1, "fairings": [{"defaults": f, "modelMatrix": model.T.reshape(-1).tolist()} for f in fairings],
              "controls": modules(definition, "Data_ControlSurface"), "procedural": [], "warnings": []}
    components = [d for t, d in docs.values() if t == 114 and "_meshOptionPrefabs" in d]
    for component in components:
        options = []
        for option in component["_meshOptionPrefabs"]:
            candidates = [p for p in assets.glob(option["Name"]+"*.prefab") if p.stem == option["Name"] or p.stem[len(option["Name"]):].lstrip('_').isdigit()]
            path = next((p for p in sorted(candidates) if any(t == 114 and "NamedMeshBonePairs" in d for t, d in exporter.unity(p).values())), assets/(option["Name"]+".prefab"))
            try: options.append(rig(path, guids, exporter))
            except Exception as exception:
                options.append(None); result["warnings"].append(option["Name"]+": "+str(exception))
        # ProcedurallyEditablePart instantiates each option under model with a 90-degree Z rotation.
        rotate = np.array([[0, -1, 0, 0], [1, 0, 0, 0], [0, 0, 1, 0], [0, 0, 0, 1]])
        result["procedural"].append({"options": options, "modelMatrix": (model @ rotate).T.reshape(-1).tolist()})
    if procedural and not components: result["warnings"].append("No exported procedural rig; saved shape cannot be previewed.")
    if fairings and not prefab: result["warnings"].append("Fairing model transform is unavailable; placement uses the part origin.")
    return result
