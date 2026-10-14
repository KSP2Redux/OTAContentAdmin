"""Bake untextured Unity part prefabs and deployment endpoints into private GLBs.

Requires numpy and PyYAML. Reads AssetRipper YAML; never runs prefab scripts.
"""
import argparse
from functools import lru_cache
import hashlib
import json
from pathlib import Path
import re
import struct
import subprocess
import sys
import numpy as np
import yaml
from preview_geometry import is_helper


def unity(path):
    text = Path(path).read_text(encoding="utf-8-sig")
    ids = re.findall(r"^--- !u!(\d+) &(-?\d+)", text, re.M)
    text = re.sub(r"^%.*\n", "", text, flags=re.M)
    text = re.sub(r"^--- !u!\d+ &-?\d+.*$", "---", text, flags=re.M)
    # Numeric-only hex buffers are bytes, not YAML integers or scientific notation.
    text = re.sub(r"^(\s*(?:_typelessdata|m_IndexBuffer):)\s+([a-fA-F0-9]+)\s*$", lambda match: match[1]+" "+json.dumps(match[2]), text, flags=re.M)
    return {int(i): (int(t), next(iter(d.values()))) for (t, i), d in zip(ids, yaml.load_all(text, Loader=yaml.CSafeLoader)) if d}


@lru_cache(maxsize=24)
def asset(path):
    return unity(path)


def transform(data):
    p = data.get("m_LocalPosition", {"x": 0, "y": 0, "z": 0})
    s = data.get("m_LocalScale", {"x": 1, "y": 1, "z": 1})
    q = data.get("m_LocalRotation", {"x": 0, "y": 0, "z": 0, "w": 1})
    x, y, z, w = (q[k] for k in "xyzw")
    n = x*x+y*y+z*z+w*w
    n = n or 1
    r = np.array([[1-2*(y*y+z*z)/n, 2*(x*y-z*w)/n, 2*(x*z+y*w)/n],
                  [2*(x*y+z*w)/n, 1-2*(x*x+z*z)/n, 2*(y*z-x*w)/n],
                  [2*(x*z-y*w)/n, 2*(y*z+x*w)/n, 1-2*(x*x+y*y)/n]])
    m = np.eye(4)
    m[:3, :3] = r @ np.diag([s[k] for k in "xyz"])
    m[:3, 3] = [p[k] for k in "xyz"]
    return m


@lru_cache(maxsize=24)
def mesh(path):
    d = next(v for t, v in asset(path).values() if t == 43)
    v = d["m_VertexData"]
    count = v["m_VertexCount"]
    raw = bytes.fromhex(str(v["_typelessdata"]))
    types = {0: "<f4", 1: "<f2", 2: "u1", 3: "i1", 4: "<u2", 5: "<i2", 6: "u1", 7: "i1", 8: "<u2", 9: "<i2", 10: "<u4", 11: "<i4"}
    strides = {}
    for ch in v["m_Channels"]:
        dim = ch["dimension"] & 15
        if dim:
            size = np.dtype(types[ch["format"]]).itemsize
            strides[ch["stream"]] = max(strides.get(ch["stream"], 0), ch["offset"] + size*dim)
    offsets = {}
    at = 0
    for stream, stride in sorted(strides.items()):
        offsets[stream] = at
        at += stride*count
        at = (at+15)//16*16
    def channel(index):
        ch = v["m_Channels"][index]
        dim = ch["dimension"] & 15
        if not dim:
            return None
        dtype = np.dtype(types[ch["format"]])
        a = np.ndarray((count, dim), dtype, buffer=raw, offset=offsets[ch["stream"]]+ch["offset"], strides=(strides[ch["stream"]], dtype.itemsize)).copy()
        if ch["format"] in (2, 4): a = a/np.iinfo(dtype).max
        if ch["format"] in (3, 5): a = np.maximum(-1, a/np.iinfo(dtype).max)
        return a
    dtype = np.dtype("<u4" if d.get("m_IndexFormat") else "<u2")
    buffer = bytes.fromhex(str(d["m_IndexBuffer"]))
    indices = [np.frombuffer(buffer, dtype, offset=s["firstByte"], count=s["indexCount"]).astype(np.uint32)+s.get("baseVertex", 0) for s in d["m_SubMeshes"] if s["topology"] == 0]
    if not indices: raise ValueError("No triangle submesh")
    binds = [np.array([[b[f"e{r}{c}"] for c in range(4)] for r in range(4)]) for b in d.get("m_BindPose", [])]
    return channel(0).astype(np.float64), np.concatenate(indices), channel(12), channel(13), binds


def glb(path, positions, indices):
    positions = positions.astype("<f4")
    positions[:, 2] *= -1
    indices = indices.reshape(-1, 3)[:, [0, 2, 1]].reshape(-1).astype("<u4")
    vertex = positions.tobytes()
    binary = vertex + indices.tobytes()
    d = {"asset": {"version": "2.0", "generator": "Redux OTA private part exporter"}, "scene": 0, "scenes": [{"nodes": [0]}], "nodes": [{"mesh": 0}],
         "meshes": [{"primitives": [{"attributes": {"POSITION": 0}, "indices": 1, "material": 0}]}],
         "materials": [{"pbrMetallicRoughness": {"baseColorFactor": [0.6, 0.65, 0.7, 1], "metallicFactor": 0.25, "roughnessFactor": 0.65}, "doubleSided": True}],
         "buffers": [{"byteLength": len(binary)}], "bufferViews": [{"buffer": 0, "byteOffset": 0, "byteLength": len(vertex), "target": 34962}, {"buffer": 0, "byteOffset": len(vertex), "byteLength": indices.nbytes, "target": 34963}],
         "accessors": [{"bufferView": 0, "componentType": 5126, "count": len(positions), "type": "VEC3", "min": positions.min(axis=0).tolist(), "max": positions.max(axis=0).tolist()}, {"bufferView": 1, "componentType": 5125, "count": len(indices), "type": "SCALAR"}]}
    encoded = json.dumps(d, separators=(",", ":")).encode()
    encoded += b" "*((-len(encoded))%4)
    binary += b"\0"*((-len(binary))%4)
    path.write_bytes(struct.pack("<4sII", b"glTF", 2, 12+8+len(encoded)+8+len(binary))+struct.pack("<I4s", len(encoded), b"JSON")+encoded+struct.pack("<I4s", len(binary), b"BIN\0")+binary)
    return {"min": d["accessors"][0]["min"], "max": d["accessors"][0]["max"]}


def bake(prefab, guids, pose):
    docs = expand_prefab(prefab, guids)
    transforms = {i: dict(d) for i, (t, d) in docs.items() if t == 4}
    games = {i: d for i, (t, d) in docs.items() if t == 1}
    game_transform = {d["m_GameObject"]["fileID"]: i for i, d in transforms.items()}
    for transform_data in transforms.values():
        if transform_data['m_Father']['fileID'] == 0:
            games[transform_data['m_GameObject']['fileID']]['m_IsActive'] = 1
            # The menu overwrites a prefab root's position and rotation with the saved part pose.
            # Bake only its inherited scale; authored scene placement is not part geometry.
            transform_data['m_LocalPosition'] = {'x':0,'y':0,'z':0}
            transform_data['m_LocalRotation'] = {'x':0,'y':0,'z':0,'w':1}
    children = {}
    for i, t in transforms.items(): children.setdefault(t["m_Father"]["fileID"], []).append(i)
    hidden = set()
    clips_applied = 0
    for i, (typ, animator) in docs.items():
        if typ != 95: continue
        controller_path = guids.get(animator.get("m_Controller", {}).get("guid"))
        if not controller_path: continue
        states = [d for t, d in asset(str(controller_path)).values() if t == 1102]
        desired = ["opened", "extended", "deployed", "open", "extend", "deploy"] if pose == "extended" else ["closed", "retracted", "stowed", "close", "retract", "stow"]
        desired += ['on'] if pose == 'extended' else ['off']
        selected = next((s for name in desired for s in states if ('on' if s.get('m_Name') is True else 'off' if s.get('m_Name') is False else str(s.get("m_Name", "")).lower()) == name), None)
        clip_path = guids.get((selected or {}).get("m_Motion", {}).get("guid"))
        if not clip_path: continue
        clip = next((d for t, d in asset(str(clip_path)).values() if t == 74), None)
        if not clip: continue
        start = game_transform[animator["m_GameObject"]["fileID"]]
        paths = {"": start}
        def visit(parent, prefix):
            for child in children.get(parent, []):
                name = games[transforms[child]["m_GameObject"]["fileID"]]["m_Name"]
                path = prefix+"/"+name if prefix else name
                paths[path] = child
                visit(child, path)
        visit(start, "")
        for key, field in [("m_RotationCurves", "m_LocalRotation"), ("m_PositionCurves", "m_LocalPosition"), ("m_ScaleCurves", "m_LocalScale")]:
            for curve in clip.get(key, []):
                node = paths.get(curve.get("path") or "")
                keys = curve.get("curve", {}).get("m_Curve", [])
                if node is not None and keys: transforms[node][field] = keys[-1]["value"]
        for curve in clip.get("m_FloatCurves", []):
            if curve.get("attribute") == "m_IsActive":
                node = paths.get(curve.get("path") or "")
                keys = curve.get("curve", {}).get("m_Curve", [])
                if node is not None and keys:
                    games[transforms[node]['m_GameObject']['fileID']]['m_IsActive'] = int(keys[-1]['value'] >= 0.5)
            elif curve.get('attribute') == 'm_Enabled':
                node=paths.get(curve.get('path') or '')
                keys=curve.get('curve',{}).get('m_Curve',[])
                if node is not None and keys:
                    game_id=transforms[node]['m_GameObject']['fileID']
                    for renderer_type,renderer_data in docs.values():
                        if renderer_type in (23,137) and renderer_data.get('m_GameObject',{}).get('fileID') == game_id:
                            renderer_data['m_Enabled']=int(keys[-1]['value'] >= 0.5)
        clips_applied += 1
    excluded = set()
    for typ, d in docs.values():
        if typ == 205 and d.get("m_LODs"):
            lods = d["m_LODs"]
            pick = 1 if len(lods) > 1 else 0
            keep = {r["renderer"]["fileID"] for r in lods[pick].get("renderers", [])}
            all_renderers = {r["renderer"]["fileID"] for lod in lods for r in lod.get("renderers", [])}
            excluded.update(all_renderers-keep)
    @lru_cache(None)
    def world(i):
        t = transforms[i]
        m = transform(t)
        parent = t["m_Father"]["fileID"]
        return world(parent)@m if parent in transforms else m
    def visible(i):
        while i in transforms:
            game = games[transforms[i]["m_GameObject"]["fileID"]]
            if i in hidden or not game.get("m_IsActive", 1) or is_helper(game.get("m_Name", "")): return False
            i = transforms[i]["m_Father"]["fileID"]
        return True
    positions, triangles = [], []
    vertex_base = 0
    warnings = []
    for ident, (typ, renderer) in docs.items():
        if typ not in (23, 137) or ident in excluded or not renderer.get("m_Enabled", 1): continue
        gid = renderer["m_GameObject"]["fileID"]
        tid = game_transform.get(gid)
        if tid is None or not visible(tid): continue
        if typ == 137:
            ref = renderer.get("m_Mesh", {})
        else:
            filters = [d for t, d in docs.values() if t == 33 and d["m_GameObject"]["fileID"] == gid]
            if not filters: continue
            ref = filters[0].get("m_Mesh", {})
        mesh_path = guids.get(ref.get("guid"))
        if not mesh_path: continue
        try:
            p, indices, weights, bone_indices, bind = mesh(str(mesh_path))
            v = np.column_stack([p, np.ones(len(p))])
            bones = renderer.get("m_Bones", [])
            if typ == 137 and bones and bind and bone_indices is not None:
                if weights is None: weights = np.ones((len(p), 1))
                if weights.shape[1] < bone_indices.shape[1]: weights = np.column_stack([weights, 1-weights.sum(axis=1)])
                result = np.zeros((len(p), 4))
                for slot in range(bone_indices.shape[1]):
                    for bi in np.unique(bone_indices[:, slot]):
                        if int(bi) >= len(bones): raise ValueError("Unknown bone index")
                        mask = bone_indices[:, slot] == bi
                        matrix = world(bones[int(bi)]["fileID"])@bind[int(bi)]
                        result[mask] += (v[mask]@matrix.T)*weights[mask, slot:slot+1]
                p = result[:, :3]
            else:
                if typ == 137 and bones: warnings.append("Skinning unavailable for "+mesh_path.stem)
                p = (v@world(tid).T)[:, :3]
            if not np.isfinite(p).all() or indices.max() >= len(p): raise ValueError("Invalid mesh buffers")
            positions.append(p)
            triangles.append(indices+vertex_base)
            vertex_base += len(p)
        except Exception as e: warnings.append(mesh_path.name+": "+str(e))
    if not positions: raise ValueError("No supported render meshes")
    return np.concatenate(positions), np.concatenate(triangles), clips_applied, sorted(set(warnings))


def expand_prefab(path, guids, depth=0):
    from copy import deepcopy
    if depth > 8: raise ValueError('Prefab nesting exceeds eight levels')
    docs = deepcopy(unity(path))
    next_id = min([0]+list(docs))-1
    for instance_id,(typ,instance) in list(docs.items()):
        if typ != 1001: continue
        source_guid = instance.get('m_SourcePrefab',{}).get('guid')
        source_path = guids.get(source_guid)
        if not source_path or source_path.suffix != '.prefab': continue
        source = expand_prefab(source_path, guids, depth+1)
        mapping = {}
        for ident,(_,d) in docs.items():
            ref = d.get('m_CorrespondingSourceObject',{})
            if ref.get('guid') == source_guid and d.get('m_PrefabInstance',{}).get('fileID') == instance_id:
                mapping[ref['fileID']] = ident
        for ident in source:
            if ident not in mapping: mapping[ident]=next_id;next_id-=1
        def remap(value):
            if isinstance(value,dict):
                if 'fileID' in value and (not value.get('guid') or value.get('guid')==source_guid) and value['fileID'] in mapping:
                    return {**value,'fileID':mapping[value['fileID']]}
                return {k:remap(v) for k,v in value.items()}
            if isinstance(value,list):return [remap(v) for v in value]
            return value
        roots=[]
        for ident,(t,d) in source.items():
            clone=remap(d)
            if t==4 and clone.get('m_Father',{}).get('fileID')==0:
                clone['m_Father']=instance.get('m_Modification',{}).get('m_TransformParent',{'fileID':0});roots.append(mapping[ident])
            docs[mapping[ident]]=(t,clone)
        for modification in instance.get('m_Modification',{}).get('m_Modifications',[]):
            target=mapping.get(modification.get('target',{}).get('fileID'));property_path=modification.get('propertyPath','')
            if target not in docs or 'Array' in property_path:continue
            target_data=docs[target][1];keys=property_path.split('.')
            if not keys or keys[0] not in ['m_LocalPosition','m_LocalRotation','m_LocalScale','m_IsActive','m_Enabled','m_Name']:continue
            for key in keys[:-1]:target_data=target_data.setdefault(key,{})
            value=modification.get('value','')
            if keys[0]!='m_Name':
                try:value=float(value)
                except (TypeError,ValueError):continue
            target_data[keys[-1]]=value
    for typ,d in docs.values():
        if typ==4 and 'm_GameObject' not in d:raise ValueError('Unresolved nested prefab reference')
    return docs


def select_prefab(assets, name):
    # AssetRipper may give the unsuffixed filename to an imported model leaf.
    # The part prefab, including its scale and controllers, has a PartBehaviour core.
    candidates = [p for p in assets.glob(name+"*.prefab") if re.fullmatch(re.escape(name)+r"(?:_\d+)?", p.stem)]
    for path in sorted(candidates, key=lambda p: (p.stem != name, p.name)):
        if any(t == 114 and d.get("core", {}).get("data", {}).get("partName") == name for t, d in unity(path).values()):
            return path
    return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--assets", type=Path, required=True, help="AssetRipper ExportedProject/Assets")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--redux-definitions", type=Path)
    parser.add_argument("--parts", nargs="*", help="Optional part names; omitted means all part definitions")
    parser.add_argument("--procedural-only", action="store_true", help="Enrich an existing package with procedural geometry")
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    cache = args.output/'guid-index.json'
    if cache.exists():
        cached = json.loads(cache.read_text(encoding='utf-8'))
    else:
        cached = {}
    if cached.get('root') == str(args.assets.resolve()):
        guids = {g:Path(p) for g,p in cached['guids'].items()}
    else:
        print(json.dumps({'phase':'indexing mesh and animation references'}),flush=True)
        listing = subprocess.check_output(["rg.exe", "-n", "--no-heading", "^guid: ", str(args.assets), "-g", "*.asset.meta", "-g", "*.anim.meta", "-g", "*.controller.meta", "-g", "*.prefab.meta", "-g", "!**/Scripts/**"], text=True)
        guids = {}
        for line in listing.splitlines():
            match = re.match(r"(.*\.meta):\d+:guid: (\w+)$", line)
            if match: guids[match.group(2)] = Path(match.group(1)[:-5])
        cache.write_text(json.dumps({'root':str(args.assets.resolve()),'guids':{g:str(p) for g,p in guids.items()}}),encoding='utf-8')
    definitions, resources = {}, {}
    paths = list(args.assets.rglob("*.json"))
    if args.redux_definitions: paths += list(args.redux_definitions.rglob("*.json"))
    for path in paths:
        try:
            text = re.sub(r"//[^\r\n]*", "", path.read_text(encoding="utf-8-sig"))
            document = json.loads(text)
            if not isinstance(document, dict): continue
            data = document.get("data", {})
            if not isinstance(data, dict): continue
            if "partName" in data:
                data['_previewPrefab'] = document.get('PrefabAddress')
                definitions[data["partName"]] = data
            elif "massPerUnit" in data: resources[data["name"]] = data["massPerUnit"]
            elif "recipeData" in document:
                resources[document['recipeData']['name']] = document['recipeData']['ingredients']
        except (ValueError, UnicodeError): continue
    def density(name, seen=()):
        if name in seen: raise ValueError('Recursive resource recipe')
        value = resources[name]
        return sum(density(i['name'], seen+(name,))*i['unitsPerRecipeUnit'] for i in value) if isinstance(value, list) else value
    resolved = {}
    for name in resources:
        try: resolved[name] = density(name)
        except (KeyError, ValueError): pass
    resources = resolved
    catalog = {"version": 1, "parts": {}, "resources": resources}
    existing_catalog=args.output/'catalog.json'
    if (args.parts or args.procedural_only) and existing_catalog.exists():
        catalog=json.loads(existing_catalog.read_text(encoding='utf-8'))
        catalog['resources'].update(resources)
    errors = []
    from procedural_geometry import export_part
    for name, definition in sorted(definitions.items()):
        if args.parts and name not in args.parts: continue
        entry = catalog['parts'].get(name, {}) if args.procedural_only else {"mass": definition.get("mass"), "category": definition.get("category"), "resources": {r["name"]:r for r in definition.get("resourceContainers", [])}, "hashes": {}}
        if args.procedural_only:
            if not any(m.get('Name') in ['Data_Fairing','Data_ProceduralPart','Data_ControlSurface'] for p in definition.get('serializedPartModules',[]) for m in p.get('ModuleData',[])): continue
        prefab = select_prefab(args.assets, name)
        if prefab is None and not args.procedural_only:
            entry['warnings'] = ['No exported prefab; preview uses an approximation.']
        elif prefab is not None and not args.procedural_only:
            entry['prefab'] = prefab.name
            for pose in ['retracted', 'extended']:
                try:
                    p, indices, clips, warnings = bake(prefab, guids, pose)
                    filename = name+'.'+pose+'.glb'
                    bounds = glb(args.output/filename, p, indices)
                    entry[pose] = filename
                    entry['hashes'][pose] = hashlib.sha256((args.output/filename).read_bytes()).hexdigest()
                    entry['bounds'] = bounds
                    entry.setdefault('pose_clips', {})[pose] = clips
                    entry.setdefault('warnings', []).extend(warnings)
                except Exception as e: entry.setdefault('warnings', []).append(pose+': '+str(e));errors.append({'part':name,'pose':pose,'error':str(e)})
        try:
            specification = export_part(args.assets, guids, definition, sys.modules[__name__])
            if specification:
                filename = name+'.procedural.json'
                (args.output/filename).write_text(json.dumps(specification,separators=(',',':'),allow_nan=False),encoding='utf-8')
                entry['procedural'] = filename
                entry.setdefault('hashes',{})['procedural'] = hashlib.sha256((args.output/filename).read_bytes()).hexdigest()
        except Exception as exception:
            entry.setdefault('warnings',[]).append('Procedural export: '+str(exception));errors.append({'part':name,'pose':'procedural','error':str(exception)})
        catalog['parts'][name] = entry
        print(json.dumps({'part': name, 'models': sum(p in entry for p in ['retracted','extended']), 'warnings': len(entry.get('warnings', []))}), flush=True)
    (args.output/'catalog.json').write_text(json.dumps(catalog,separators=(',',':')),encoding='utf-8')
    (args.output/'export-report.json').write_text(json.dumps({'parts':len(catalog['parts']),'errors':errors},indent=2),encoding='utf-8')
    print(json.dumps({'complete': True, 'parts': len(catalog['parts']), 'errors': len(errors)}))


if __name__ == '__main__': main()
