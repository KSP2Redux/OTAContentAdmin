"""Optional Blender supplement for Redux FBX parts absent from the stock export.

Run in a new background Blender process with --factory-startup; never in a user's scene.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
import bpy
sys.path.insert(0, str(Path(__file__).resolve().parent))
from preview_geometry import is_helper


parser=argparse.ArgumentParser()
parser.add_argument('--definitions',type=Path,required=True)
parser.add_argument('--package',type=Path,required=True)
parser.add_argument('--parts',nargs='*')
parser.add_argument('--force',action='store_true',help='Rebuild existing FBX supplements')
args=parser.parse_args(sys.argv[sys.argv.index('--')+1:])
catalog_path=args.package/'catalog.json'
catalog=json.loads(catalog_path.read_text(encoding='utf-8'))
report=[]
for definition in sorted(args.definitions.rglob('*.json')):
    try:
        d=json.loads(re.sub(r'//[^\r\n]*','',definition.read_text(encoding='utf-8-sig'))).get('data',{})
        name=d.get('partName')
    except (ValueError,AttributeError):continue
    if not name or name not in catalog['parts'] or (args.parts and name not in args.parts):continue
    entry=catalog['parts'][name]
    if entry.get('extended') and not (args.force and any('FBX supplement' in w for w in entry.get('warnings',[]))):continue
    sources=[definition.parent/(name+suffix+'.fbx') for suffix in ['', '_lod1', '_lod0']]
    source=next((p for p in sources if p.exists()),None)
    if not source:continue
    try:
        bpy.ops.wm.read_factory_settings(use_empty=True)
        bpy.ops.import_scene.fbx(filepath=str(source.resolve()),use_anim=True)
        originals=list(bpy.context.scene.objects)
        actions=list(bpy.data.actions)
        for pose in ['retracted','extended']:
            tokens=['open','deploy','extend'] if pose=='extended' else ['close','retract','stow']
            action=next((a for word in tokens for a in actions if word in a.name.lower()),actions[0] if actions else None)
            if action:
                for obj in originals:
                    if obj.type=='ARMATURE':
                        obj.animation_data_create()
                        obj.animation_data.action=action
                closing=any(w in action.name.lower() for w in ['close','retract','stow'])
                frame=action.frame_range[1] if (pose=='extended') != closing else action.frame_range[0]
                bpy.context.scene.frame_set(int(frame))
            bpy.context.view_layer.update()
            depsgraph=bpy.context.evaluated_depsgraph_get()
            meshes=[]
            for obj in originals:
                lower=obj.name.lower()
                ancestor=obj
                helper=False
                while ancestor:
                    if is_helper(ancestor.name):helper=True;break
                    ancestor=ancestor.parent
                if obj.type!='MESH' or helper or re.search(r'lod[_ ]?[12]',lower):continue
                evaluated=obj.evaluated_get(depsgraph)
                mesh=bpy.data.meshes.new_from_object(evaluated,preserve_all_data_layers=False,depsgraph=depsgraph)
                mesh.materials.clear()
                copy=bpy.data.objects.new('preview_'+obj.name,mesh)
                bpy.context.scene.collection.objects.link(copy)
                copy.matrix_world=evaluated.matrix_world.copy()
                meshes.append(copy)
            if not meshes:raise ValueError('No FBX render meshes')
            bpy.ops.object.select_all(action='DESELECT')
            for obj in meshes:obj.select_set(True)
            filename=name+'.'+pose+'.glb'
            bpy.ops.export_scene.gltf(filepath=str((args.package/filename).resolve()),export_format='GLB',use_selection=True,export_animations=False,export_materials='NONE')
            entry[pose]=filename
            entry['hashes'][pose]=hashlib.sha256((args.package/filename).read_bytes()).hexdigest()
            entry.setdefault('pose_clips',{})[pose]=1 if action else 0
            for obj in meshes:bpy.data.objects.remove(obj,do_unlink=True)
        entry['warnings']=['Redux FBX supplement: prefab offsets, procedural geometry and non-FBX controller clips are approximate.']
        report.append({'part':name,'models':2,'animated':bool(actions)})
        print(json.dumps(report[-1]),flush=True)
    except Exception as e:report.append({'part':name,'error':str(e)})
latest=json.loads(catalog_path.read_text(encoding='utf-8'))
combined={**catalog, 'parts':{**catalog['parts'],**latest['parts']}}
for item in report:
    if 'models' in item:combined['parts'][item['part']]=catalog['parts'][item['part']]
catalog_path.write_text(json.dumps(combined,separators=(',',':')),encoding='utf-8')
(args.package/'fbx-report.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
print(json.dumps({'supplemented':sum('models' in p for p in report),'failed':sum('error' in p for p in report)}),flush=True)
