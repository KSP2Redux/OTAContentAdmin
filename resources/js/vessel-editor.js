import './bootstrap';
import './vessel-editor.css';
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { placeTravelArrow, projectedBounds } from './vessel-travel-arrow';
import { buildSavedProcedural } from './procedural-vessel';

function boot() {
    const host = document.getElementById('vessel-editor');
    if (!host || host.dataset.initialized) return;
    host.dataset.initialized = '1';
    const config = JSON.parse(document.getElementById('vessel-editor-config').textContent);
    const $ = id => host.querySelector(`#ve-${id}`);
    let workspace = null, catalog = { parts: {} }, selected = null, renderVersion = 0, playback = null, home = new THREE.Vector3();
    let busy = false, modelCount = 0, missingCount = 0;
    const nodes = new Map(), modelCache = new Map(), proceduralCache = new Map(), partWarnings = new Map(), loader = new GLTFLoader();
    const scene = new THREE.Scene(); scene.background = new THREE.Color('#101b2c');
    const camera = new THREE.PerspectiveCamera(38, 1, 0.05, 1000000);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(devicePixelRatio, 2)); renderer.setClearColor('#101b2c');
    renderer.toneMapping = THREE.ACESFilmicToneMapping; renderer.toneMappingExposure = 1;
    const room = new RoomEnvironment(), pmrem = new THREE.PMREMGenerator(renderer);
    const environment = pmrem.fromScene(room,0.04); scene.environment = environment.texture; scene.environmentIntensity = 0.8;
    room.dispose(); pmrem.dispose();
    const previewMaterial = () => new THREE.MeshStandardMaterial({color:0xb8c0c8,metalness:0.6,roughness:0.38,side:THREE.DoubleSide});
    $('canvas').append(renderer.domElement);
    const controls = new OrbitControls(camera, renderer.domElement); controls.enableDamping = true;
    scene.add(new THREE.HemisphereLight(0xcbdfff, 0x263144, 1.2));
    const key = new THREE.DirectionalLight(0xffead5, 2); key.position.set(20, 35, 45); scene.add(key);
    const fill = new THREE.DirectionalLight(0x77aaff, 1); fill.position.set(-35, 5, -20); scene.add(fill);
    let vessel = new THREE.Group(); scene.add(vessel);
    const localBounds = new THREE.Box3(), vesselBounds = new THREE.Box3();
    const arrow = new THREE.ArrowHelper(new THREE.Vector3(0, 1, 0), new THREE.Vector3(), 8, 0x38bdf8, 2, 1);
    arrow.visible = false;
    scene.add(arrow);
    const selection = new THREE.BoxHelper(undefined, 0x67e8f9); selection.visible = false; scene.add(selection);
    const clock = new THREE.Clock();
    const resize = () => { const w = $('canvas').clientWidth, h = $('canvas').clientHeight; renderer.setSize(w, h); camera.aspect = w/h; camera.updateProjectionMatrix(); };
    const observer = new ResizeObserver(resize); observer.observe($('canvas')); resize();
    const message = (text, error = false) => { $('status').textContent = text; $('status').classList.toggle('ve-error', error); };
    const setBusy = value => { busy = value; host.setAttribute('aria-busy', String(value)); for (const id of ['stage', 'download', 'deploy-all', 'retract-all', 'minus', 'plus', 'flip']) $(id).disabled = value || !workspace; };
    const request = async (url, data, file = false) => {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json', ...(file ? {} : {'Content-Type': 'application/json'}) }, body: file ? data : JSON.stringify(data) });
        const result = await response.json();
        if (!response.ok) throw new Error(Object.values(result.errors ?? {}).flat().join(' ') || result.message || 'Request failed.');
        return result;
    };
    const guard = task => async (...args) => { if (busy) return; setBusy(true); try { await task(...args); } catch (error) { message(error.message, true); choose(selected); } finally { setBusy(false); } };
    const fetchModel = url => { if (!modelCache.has(url)) modelCache.set(url, loader.loadAsync(url)); return modelCache.get(url); };
    const fetchProcedural = url => { if (!proceduralCache.has(url)) proceduralCache.set(url, fetch(url).then(async response => { if (!response.ok) throw new Error('Procedural model data is unavailable.'); return response.json(); })); return proceduralCache.get(url); };
    const pose = () => $('pose').value === 'leaning' ? new THREE.Quaternion(-0.4744, 0, -0.2726, 0.837).normalize() : new THREE.Quaternion();
    const disposeVessel = () => { vessel.traverse(o => { if (o.isMesh) { o.material.dispose(); if (o.userData.placeholder || o.userData.procedural) o.geometry.dispose(); } }); scene.remove(vessel); };
    function choose(guid) {
        selected = guid; $('part').value = guid ?? '';
        const part = workspace?.parts.find(p => p.guid === guid), node = nodes.get(guid);
        $('deployed').disabled = !part || part.deployed === null || part.canDeploy === false;
        $('deployed').checked = part?.deployed === true;
        $('deploy-label').textContent = part?.deploymentKind === 'fairing' ? 'Fairing / shroud jettisoned' : part?.deploymentKind === 'control' ? 'Control surface deployed' : 'Deployed';
        $('part-note').textContent = part ? `${part.name.replaceAll('_', ' ')}${part.canDeploy === false ? '  /  fixed part' : part.deployed === null ? '  /  no deployment control' : ''}${partWarnings.get(part.guid)?.length ? ' / '+partWarnings.get(part.guid).join(' ') : catalog.parts[part.name]?.warnings?.length ? ' / approximate model pose' : ''}` : 'Select a part.';
        selection.visible = !!node; if (node) selection.setFromObject(node);
    }
    function listParts() {
        const filter = $('search').value.toLowerCase(); $('part').replaceChildren();
        workspace?.parts.forEach((p, i) => { if (p.name.toLowerCase().includes(filter)) { const option = new Option(`${i+1}. ${p.name.replaceAll('_', ' ')}${p.deployed === null ? '' : p.deployed ? '  /  deployed' : '  /  retracted'}`, p.guid); $('part').add(option); } });
        if (selected) $('part').value = selected;
    }
    function frame() {
        playback = null; vessel.position.copy(home); vessel.updateMatrixWorld(true);
        const box = new THREE.Box3().setFromObject(vessel), size = box.getSize(new THREE.Vector3());
        const span = Math.max(size.x, size.y, size.z, 3); const center = box.getCenter(new THREE.Vector3());
        camera.position.copy(center).add(new THREE.Vector3(span*0.9, span*0.45, span*1.65));
        controls.target.copy(center); controls.update();
        for (let attempt = 0; attempt < 8; attempt++) {
            updateArrow();
            const framed = box.clone().union(new THREE.Box3().setFromObject(arrow));
            const projected = projectedBounds(framed, camera);
            if (Math.max(Math.abs(projected.min.x), Math.abs(projected.max.x), Math.abs(projected.min.y), Math.abs(projected.max.y)) <= 0.92) break;
            camera.position.sub(center).multiplyScalar(1.15).add(center); controls.update();
        }
    }
    function updateArrow() {
        vessel.updateMatrixWorld(true);
        vesselBounds.copy(localBounds).applyMatrix4(vessel.matrixWorld);
        placeTravelArrow(arrow, vesselBounds, camera, new THREE.Vector3(0, 1, 0).applyQuaternion(vessel.quaternion));
    }
    function orient(resetFrame = false) {
        playback = null; vessel.quaternion.copy(pose()); vessel.position.copy(home);
        if (resetFrame) {
            vessel.position.set(0, 0, 0); vessel.updateMatrixWorld(true);
            home.copy(new THREE.Box3().setFromObject(vessel).getCenter(new THREE.Vector3())).negate(); vessel.position.copy(home); frame();
        } else updateArrow();
    }
    async function display(result, initial = false) {
        const fresh = initial || workspace === null;
        workspace = result;
        if (fresh) { $('name').value = result.metadata.VehicleName || result.metadata.Name; $('mass').value = result.metadata.Mass || ''; }
        $('stats').textContent = `${result.parts.length} parts  /  ${result.assemblies} ${result.assemblies === 1 ? 'assembly' : 'assemblies'}  /  ${(result.bytes/1024).toFixed(0)} KiB`;
        listParts(); disposeVessel(); vessel = new THREE.Group(); scene.add(vessel); nodes.clear(); partWarnings.clear(); modelCount = 0; missingCount = 0;
        let proceduralCount = 0, controlPoses = 0, approximateShapes = 0;
        const version = ++renderVersion;
        for (const part of result.parts) {
            message(`Loading preview: ${nodes.size+1} / ${result.parts.length} parts...`);
            const group = new THREE.Group(); group.userData.guid = part.guid;
            group.position.set(part.position[0], part.position[1], -part.position[2]);
            group.quaternion.set(-part.rotation[0], -part.rotation[1], part.rotation[2], part.rotation[3]);
            if (group.quaternion.lengthSq() === 0) group.quaternion.identity(); else group.quaternion.normalize();
            const model = catalog.parts[part.name];
            const url = model?.[part.deployed === true ? 'extended' : 'retracted'];
            let generated = null;
            if (part.procedural?.length || part.fairings?.length || part.controls?.length) {
                try {
                    if (!model?.procedural) throw new Error('Procedural model data is unavailable; default geometry is shown.');
                    const spec = await fetchProcedural(model.procedural); if (version !== renderVersion) return;
                    generated = buildSavedProcedural(spec, part);
                    proceduralCount += generated.shapes;
                    controlPoses += generated.controlPoses;
                    if (generated.warnings.length) { partWarnings.set(part.guid, generated.warnings); approximateShapes++; }
                } catch(error) { partWarnings.set(part.guid,[error.message]); approximateShapes++; }
            }
            let modelLoaded = !!generated?.replacesModel;
            if (generated?.replacesModel) modelCount++;
            else if (url) {
                try {
                    const gltf = await fetchModel(url); if (version !== renderVersion) return;
                    const object = gltf.scene.clone(true);
                    object.traverse(mesh => { if (mesh.isMesh) { if (!mesh.geometry.attributes.normal) mesh.geometry.computeVertexNormals(); mesh.material = previewMaterial(); mesh.userData.guid = part.guid; } });
                    group.add(object); modelCount++; modelLoaded = true;
                } catch { missingCount++; }
            } else missingCount++;
            if (generated) {
                generated.object.traverse(mesh => { if (mesh.isMesh) { mesh.material.dispose(); mesh.material = previewMaterial(); mesh.userData.guid = part.guid; } });
                group.add(generated.object);
            }
            if (!modelLoaded) {
                const bounds = model?.bounds, size = bounds ? bounds.max.map((v,i) => Math.max(v-bounds.min[i], 0.1)) : [1.25,2,1.25];
                if (part.deployed !== null) size[1] *= part.deployed ? 3 : 0.5;
                const geometry = new THREE.BoxGeometry(...size); const material = previewMaterial(); material.wireframe = true;
                const mesh = new THREE.Mesh(geometry, material); mesh.userData.guid = part.guid; mesh.userData.placeholder = true; group.add(mesh);
            }
            vessel.add(group); nodes.set(part.guid, group);
        }
        $('model-status').textContent = `${modelCount} real part models  /  ${missingCount} approximations${proceduralCount ? '  /  '+proceduralCount+' saved procedural shapes' : ''}${controlPoses ? '  /  '+controlPoses+' control-surface poses' : ''}${approximateShapes ? '  /  '+approximateShapes+' procedural approximations' : ''}${Object.keys(catalog.parts).length ? '' : '. Import a private model package to enable geometry.'}`;
        localBounds.setFromObject(vessel);
        orient(fresh); choose(selected); message(result.notes?.length ? result.notes.join(' ') : 'Workspace ready to review.'); setBusy(false);
    }
    const edit = guard(async options => display(await request(config.editUrl, { contents: workspace.contents, ...options })));
    $('file').addEventListener('change', guard(async () => { const file = $('file').files[0]; if (!file) return; const form = new FormData(); form.set('file', file); selected = null; const result = await request(config.convertUrl, form, true); $('slug').value = file.name.replace(/\.json$/i, '').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''); $('action').value = 'add'; await display(result, true); }));
    $('deploy-all').onclick = () => workspace && edit({ all_deployed: true });
    $('retract-all').onclick = () => workspace && edit({ all_deployed: false });
    $('deployed').onchange = () => selected && edit({ deployment: { [selected]: $('deployed').checked } });
    $('minus').onclick = () => workspace && edit({ rotation: { axis: $('axis').value, degrees: -90 } });
    $('plus').onclick = () => workspace && edit({ rotation: { axis: $('axis').value, degrees: 90 } });
    $('flip').onclick = () => workspace && edit({ rotation: { axis: $('axis').value, degrees: 180 } });
    $('search').oninput = () => { listParts(); if (selected && $('part').value !== selected) choose(null); }; $('part').onchange = () => choose($('part').value);
    $('pose').onchange = () => orient(); $('frame').onclick = frame;
    $('rest').onclick = () => { playback = null; vessel.position.copy(home); };
    const fly = kind => {
        if (!workspace) return;
        vessel.position.copy(home); vessel.updateMatrixWorld(true); let extent = 1;
        for (const node of nodes.values()) { const size = new THREE.Box3().setFromObject(node).getSize(new THREE.Vector3()); extent = Math.max(extent, size.x, size.y, size.z); }
        const direction = new THREE.Vector3(0, 1, 0).applyQuaternion(vessel.quaternion);
        playback = { kind, start: performance.now(), duration: kind === 'in' ? 2500 : 5000, offset: direction.multiplyScalar(extent*(kind === 'in' ? 60 : 5)) };
    };
    $('in').onclick = () => fly('in'); $('out').onclick = () => fly('out');
    $('download').onclick = guard(async () => { const result = await request(config.editUrl, { contents: workspace.contents, name: $('name').value, ...($('mass').value ? {mass: Number($('mass').value)} : {}) }); await display(result); const url = URL.createObjectURL(new Blob([result.contents], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = ($('slug').value || 'vessel')+'.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); });
    $('stage').onclick = guard(async () => { const result = await request(config.stageUrl, { contents: workspace.contents, name: $('name').value, mass: Number($('mass').value), author: $('author').value, body: $('body').value, slug: $('slug').value, path: $('slug').value+'.json', action: $('action').value, change_set_id: $('change-set').value }); message(result.message); });
    renderer.domElement.addEventListener('pointerup', event => { if (!workspace) return; const rect = renderer.domElement.getBoundingClientRect(), mouse = new THREE.Vector2((event.clientX-rect.left)/rect.width*2-1, -(event.clientY-rect.top)/rect.height*2+1); const ray = new THREE.Raycaster(); ray.setFromCamera(mouse, camera); const hit = ray.intersectObject(vessel, true)[0]; if (hit) choose(hit.object.userData.guid); });
    config.bodyOptions.forEach(body => $('body').add(new Option(body, body)));
    $('body').value = 'Kerbin';
    config.changeSets.forEach(changeSet => $('change-set').add(new Option(changeSet.summary, changeSet.id)));
    const onChangeSetCreated = event => {
        const {id, summary} = event.detail;
        if (!host.isConnected || typeof id !== 'string' || typeof summary !== 'string') return;
        let option = Array.from($('change-set').options).find(option => option.value === id);
        if (option) option.textContent = summary;
        else $('change-set').add(new Option(summary, id));
        $('change-set').value = id;
        message('Change set created. Ready to save your change.');
    };
    window.addEventListener('ota-change-set-created', onChangeSetCreated);
    let frameId;
    function tick() {
        if (!host.isConnected) { window.removeEventListener('ota-change-set-created', onChangeSetCreated); cancelAnimationFrame(frameId); observer.disconnect(); controls.dispose(); disposeVessel(); environment.dispose(); renderer.dispose(); return; }
        frameId = requestAnimationFrame(tick);
        if (playback) { const t = Math.min((performance.now()-playback.start)/playback.duration, 1); const shaped = playback.kind === 'out' ? t*t*t : 1-(1-t)*(1-t)*(1-t); vessel.position.copy(home).addScaledVector(playback.offset, playback.kind === 'out' ? shaped : shaped-1); if (t === 1) playback = null; }
        if (selection.visible && nodes.has(selected)) selection.setFromObject(nodes.get(selected));
        controls.update(clock.getDelta()); updateArrow(); renderer.render(scene, camera);
    }
    tick();
    (async () => { try { catalog = await (await fetch(config.catalogUrl, { headers: {Accept:'application/json'} })).json(); if (config.path || config.operation) { setBusy(true); const sourceUrl = config.operation ? config.draftUrl+'/'+encodeURIComponent(config.operation) : config.publishedUrl+'/'+encodeURIComponent(config.path); const response = await fetch(sourceUrl, {headers:{Accept:'application/json'}}); const result = await response.json(); if (!response.ok) throw new Error(result.message); $('author').value = result.published.author; $('body').value = result.published.body; $('action').value = result.published.action || 'replace'; $('slug').value = result.published.path.replace(/\.json$/, ''); if (result.published.change_set_id) $('change-set').value = result.published.change_set_id; await display(result, true); } else $('model-status').textContent = `${Object.keys(catalog.parts).length} private part definitions available.`; } catch(error) { message(error.message, true); } finally { setBusy(false); } })();
}
document.addEventListener('livewire:navigated', boot);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
