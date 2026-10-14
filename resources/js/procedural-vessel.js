import * as THREE from 'three';

const value = (property, fallback) => property?.storedValue ?? property ?? fallback;
const vector = values => new THREE.Vector3(...values);
const clamp = value => THREE.MathUtils.clamp(value, 0, 1);
const unityEuler = values => new THREE.Quaternion().setFromEuler(new THREE.Euler(...values.map(THREE.MathUtils.degToRad), 'ZXY'));

export function sampleCurve(keys, time) {
    if (!keys.length) return 1;
    if (time <= keys[0].time) return keys[0].value;
    if (time >= keys.at(-1).time) return keys.at(-1).value;
    const right = keys.findIndex(key => key.time > time), a = keys[right - 1], b = keys[right], duration = b.time - a.time;
    if (!Number.isFinite(a.outSlope) || !Number.isFinite(b.inSlope)) return a.value;
    const cubic = (p0, p1, p2, p3, t) => (1-t)**3*p0 + 3*(1-t)**2*t*p1 + 3*(1-t)*t*t*p2 + t**3*p3;
    const out = a.weightedMode & 2 ? a.outWeight : 1/3, incoming = b.weightedMode & 1 ? b.inWeight : 1/3;
    let low = 0, high = 1;
    for (let i = 0; i < 24; i++) { const t = (low + high)/2; if (cubic(0, out, 1-incoming, 1, t) < (time-a.time)/duration) low = t; else high = t; }
    return cubic(a.value, a.value + a.outSlope*duration*out, b.value - b.inSlope*duration*incoming, b.value, (low + high)/2);
}

export function solveRig(rig, state = {}, poses = []) {
    const nodes = new Map(rig.nodes.map(n => [n.id, { ...n, p: vector(n.position), q: new THREE.Quaternion(...n.rotation), s: vector(n.scale) }]));
    const world = new Map();
    function refresh() {
        world.clear();
        const visit = (id, pending = new Set()) => {
            if (world.has(id)) return world.get(id);
            const node = nodes.get(id); if (!node || pending.has(id)) throw new Error('Invalid procedural bone hierarchy.');
            pending.add(id); const m = new THREE.Matrix4().compose(node.p, node.q, node.s);
            if (nodes.has(node.parent)) m.premultiply(visit(node.parent, pending));
            pending.delete(id); world.set(id, m); return m;
        };
        for (const id of nodes.keys()) visit(id);
    }
    refresh();
    const original = new Map([...world].map(([id, m]) => [id, m.clone()]));
    const sliders = Object.fromEntries(rig.sliders.map(s => [s.name, clamp(Number(state.sliderData?.[s.name] ?? s.default))]));
    if (Object.values(sliders).some(v => !Number.isFinite(v))) throw new Error('Invalid saved procedural slider value.');
    const offsets = new Map();
    for (const slider of rig.sliders) {
        const multiplier = slider.limits.length ? Math.min(...slider.limits.map(l => sampleCurve(l.curve, sliders[l.parent] ?? 0))) : 1;
        const amount = sliders[slider.name]*multiplier + slider.neutral*(1-multiplier);
        for (const target of slider.targets) {
            const key = `${target.node}:${target.mode}`, offset = vector(target.min).lerp(vector(target.max), amount);
            if (!offsets.has(key)) offsets.set(key, new THREE.Vector3()); offsets.get(key).add(offset);
        }
    }
    for (const node of nodes.values()) {
        if (offsets.has(`${node.id}:0`)) node.p.add(offsets.get(`${node.id}:0`));
        if (offsets.has(`${node.id}:1`)) { const e = new THREE.Euler().setFromQuaternion(node.q, 'ZXY'); node.q.copy(unityEuler([e.x, e.y, e.z].map(THREE.MathUtils.radToDeg).map((v,i) => v + offsets.get(`${node.id}:1`).getComponent(i)))); }
        if (offsets.has(`${node.id}:2`)) node.s.add(offsets.get(`${node.id}:2`));
    }
    for (const pose of poses) if (nodes.has(pose.node)) nodes.get(pose.node).q.multiply(pose.rotation);
    refresh();
    const decompose = matrix => { const p = new THREE.Vector3(), q = new THREE.Quaternion(), s = new THREE.Vector3(); matrix.decompose(p,q,s); return {p,q,s}; };
    const weighted = (sources, map, property) => {
        let sum = 0; const result = new THREE.Vector3();
        for (const source of sources) if (map.has(source.node)) { result.addScaledVector(decompose(map.get(source.node))[property], source.weight); sum += source.weight; }
        return sum ? result.divideScalar(sum) : null;
    };
    const look = (direction, up) => new THREE.Quaternion().setFromRotationMatrix(new THREE.Matrix4().lookAt(direction, new THREE.Vector3(), up));
    for (let iteration = 0; iteration < 12; iteration++) {
        for (const constraint of rig.constraints) {
            const node = nodes.get(constraint.node); if (!node) continue;
            const mode = constraint.mode === 'position' ? 3 : constraint.mode === 'scale' ? 5 : 4;
            const offset = offsets.get(`${node.id}:${mode}`)?.x ?? 0;
            const sources = constraint.sources.map((s,i) => ({...s, weight: THREE.MathUtils.clamp(s.weight + (i === 0 ? offset : -offset), 0, 100)}));
            const target = weighted(sources, world, constraint.mode === 'scale' ? 's' : 'p'); if (!target) continue;
            const parent = world.get(node.parent) ?? new THREE.Matrix4();
            if (constraint.mode === 'position') {
                target.add(vector(constraint.offset)).applyMatrix4(parent.clone().invert());
                for (let a = 0; a < 3; a++) if (constraint.axes[a]) node.p.setComponent(a, THREE.MathUtils.lerp(node.p.getComponent(a), target.getComponent(a), constraint.weight));
            } else if (constraint.mode === 'scale') {
                target.multiply(vector(constraint.offset)).divide(decompose(parent).s);
                for (let a = 0; a < 3; a++) if (constraint.axes[a]) node.s.setComponent(a, THREE.MathUtils.lerp(node.s.getComponent(a), target.getComponent(a), constraint.weight));
            } else {
                const initial = decompose(original.get(node.id)), baseTarget = weighted(constraint.sources, original, 'p');
                const baseDirection = baseTarget.sub(initial.p).normalize();
                let up = new THREE.Vector3(0, 1, 0).applyQuaternion(initial.q);
                if (Math.abs(up.dot(baseDirection)) > .99) up = new THREE.Vector3(1, 0, 0).applyQuaternion(initial.q);
                if (constraint.useUp && world.has(constraint.up)) {
                    const reference = decompose(world.get(constraint.up));
                    up = constraint.upMode === 'rotation' ? vector(constraint.upVector).applyQuaternion(reference.q).normalize() : reference.p.sub(decompose(world.get(node.id)).p).normalize();
                }
                const direction = target.sub(decompose(world.get(node.id)).p).normalize();
                if (direction.lengthSq() < .5) continue;
                const rotation = look(direction, up).multiply(look(baseDirection, up).invert()).multiply(initial.q);
                rotation.premultiply(decompose(parent).q.invert());
                if (offset) rotation.multiply(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0,0,1), THREE.MathUtils.degToRad(offset)));
                node.q.slerp(rotation, constraint.weight);
            }
            refresh();
        }
    }
    return { nodes, world };
}

function controlPose(control) {
    const pivotName = control.CtrlSurfaceDeployTransformName && control.CtrlSurfaceDeployTransformName !== 'default' ? control.CtrlSurfaceDeployTransformName : control.CtrlSurfacePivotTransformName;
    const separate = control.CtrlSurfaceDeployTransformName && control.CtrlSurfaceDeployTransformName !== 'default';
    const axisName = separate ? 'DeployTransformRotAxis' : 'CtrlTransformRotAxis';
    const axis = {X:[1,0,0],Y:[0,1,0],Z:[0,0,1]}[control[axisName]] ?? [[1,0,0],[0,1,0],[0,0,1]][control[axisName] ?? 0];
    const sign = (control.CtrlTransformSign ?? 1) * (!separate && control.MirrorDeploy && control.ReverseMirrorDeployement ? -1 : 1);
    let angle = Number(value(control.DeployAngle, NaN));
    if (!Number.isFinite(angle)) angle = control.UseCtrlSurfaceDeployAngle ? control.CtrlSurfaceDeployAngle ?? 15 : control.CtrlSurfaceRange ?? 15;
    return {pivotName, axis:vector(axis), angle:THREE.MathUtils.degToRad((value(control.Deploy,false) ? angle : 0)*sign)};
}

export function buildWing(rig, state, mirrored, modelMatrix, control = {}, nodeDeployment = false) {
    const pose = controlPose(control), target = rig.nodes.find(n => n.name === pose.pivotName);
    const poses = nodeDeployment && target ? [{node:target.id,rotation:new THREE.Quaternion().setFromAxisAngle(pose.axis,pose.angle)}] : [];
    const { nodes, world } = solveRig(rig, state, poses), result = new THREE.Group(), modelTransform = new THREE.Matrix4().fromArray(modelMatrix);
    const pivot = [...nodes.values()].find(n => n.name === pose.pivotName);
    let deployment = null;
    if (!nodeDeployment && pivot && value(control.Deploy, false)) {
        const neutral = world.get(pivot.id), rotation = new THREE.Matrix4().makeRotationAxis(pose.axis,pose.angle);
        deployment = neutral.clone().multiply(rotation).multiply(neutral.clone().invert());
    }
    for (const mesh of rig.meshes) {
        if (state.visibleMeshData?.[mesh.name] === false || (mesh.name === 'CONTROLSURFACE' && state.toggleData?.ControlSurface === false)) continue;
        const meshMatrix = world.get(mesh.node), inverseMesh = meshMatrix.clone().invert(), matrices = mesh.bones.map((id,i) => world.get(id).clone().multiply(new THREE.Matrix4().fromArray(mesh.binds[i])));
        const positions = [], p = new THREE.Vector3(), transformed = new THREE.Vector3();
        for (let v = 0; v < mesh.positions.length/3; v++) {
            p.fromArray(mesh.positions,v*3); const point = new THREE.Vector3();
            if (matrices.length && mesh.weights && mesh.joints) {
                mesh.joints[v].forEach((j,s) => { if (mesh.weights[v][s] && matrices[j]) point.addScaledVector(transformed.copy(p).applyMatrix4(matrices[j]),mesh.weights[v][s]); });
            } else point.copy(p).applyMatrix4(meshMatrix);
            if (mirrored) { point.applyMatrix4(inverseMesh); point.z *= -1; point.applyMatrix4(meshMatrix); }
            if (deployment && mesh.attach === pivot.id) point.applyMatrix4(deployment);
            point.applyMatrix4(modelTransform); positions.push(point.x,point.y,-point.z);
        }
        const indices = mesh.indices.slice(); if (!mirrored) for (let i=0;i<indices.length;i+=3) [indices[i+1],indices[i+2]] = [indices[i+2],indices[i+1]];
        const geometry = new THREE.BufferGeometry().setAttribute('position',new THREE.Float32BufferAttribute(positions,3)); geometry.setIndex(indices); geometry.computeVertexNormals(); geometry.computeBoundingBox();
        const object = new THREE.Mesh(geometry); object.userData.procedural = true; result.add(object);
    }
    return result;
}

export function buildControlSurface(rig, control) {
    return buildWing(rig,{},false,new THREE.Matrix4().toArray(),control,true);
}

export function resolvedFairingStartHeight(definition, modelMatrix) {
    const axis = new THREE.Vector3(definition.LocalUpAxis?.x ?? 0,definition.LocalUpAxis?.y ?? 1,definition.LocalUpAxis?.z ?? 0);
    const sign = a => a < 0 ? -1 : 1, offset = vector(new THREE.Matrix4().fromArray(modelMatrix).elements.slice(12,15));
    const upSign = sign(axis.x)*sign(axis.y)*sign(axis.z), modelSign = sign(offset.x)*sign(offset.y)*sign(offset.z);
    return (definition.FairingStartHeight ?? 0)*upSign - offset.multiply(axis).length()*upSign*modelSign;
}

export function buildFairing(definition, saved = {}, modelMatrix) {
    const data = {...definition};
    // These are saved states; axis, pivot and shell settings belong to the part definition.
    for (const key of ['CrossSections','FairingEnabled','IsDeployed','IsCapped','IsShroud']) if (Object.hasOwn(saved,key)) data[key]=saved[key];
    if (!value(data.FairingEnabled,true) || value(data.IsDeployed,false)) return null;
    const sections = (data.CrossSections ?? []).map(s => ({...s})); if (sections.length < 2) return null;
    if (sections.length > 512) throw new Error('Fairing profile exceeds the preview limit of 512 sections.');
    const axis = new THREE.Vector3(data.LocalUpAxis?.x ?? 0,data.LocalUpAxis?.y ?? 1,data.LocalUpAxis?.z ?? 0).normalize();
    const pivot = new THREE.Vector3(data.Pivot?.x ?? 0,data.Pivot?.y ?? 0,data.Pivot?.z ?? 0);
    if (!Object.hasOwn(saved,'CrossSections')) {
        const start = resolvedFairingStartHeight(data,modelMatrix);
        sections.forEach(s => s.Height += start);
    }
    const last = sections.at(-1), previous = sections.at(-2);
    if (last.IsLast && Math.abs(last.Radius-(data.CapRadius ?? .375)) < 1e-6 && last.Radius > 0) {
        const slope = (last.Height-previous.Height)/(previous.Radius-last.Radius);
        sections.push({Height:last.Height+last.Radius*slope*(data.NoseTip ?? .5),Radius:0,IsCap:true});
    }
    const rings = [], slope = (a,b) => (b.Radius-a.Radius)/(b.Height-a.Height || 1e-9);
    for (let i=0;i<sections.length-1;i++) {
        const a=sections[i],b=sections[i+1]; if (!Number.isFinite(a.Height+b.Height+a.Radius+b.Radius)) throw new Error('Invalid saved fairing profile.');
        if (!i) rings.push(a);
        if (!b.IsCap) {
            const t = clamp((data.FairingSmoothingAngle ?? 35)*Math.PI/180), edge = data.EdgeWarp ?? .02;
            const lower = i ? (slope(sections[i-1],a)-slope(a,b))*edge : 0;
            const upper = i+2<sections.length ? (slope(a,b)-slope(b,sections[i+2]))*edge : 0;
            const mid = {Height:THREE.MathUtils.lerp(a.Height,b.Height,t),Radius:THREE.MathUtils.lerp(a.Radius,b.Radius,t)+lower};
            const next = {Height:THREE.MathUtils.lerp(b.Height,mid.Height,t),Radius:THREE.MathUtils.lerp(b.Radius,mid.Radius,t)+upper};
            rings.push(...[mid,next].sort((a,b)=>a.Height-b.Height));
        }
        rings.push(b);
    }
    const sides = THREE.MathUtils.clamp(Math.round(data.FairingSideCount ?? 24),8,144), thickness = Math.max(0,data.FairingThickness ?? .05);
    const radial = new THREE.Vector3(0,0,1).addScaledVector(axis,-axis.z); if (radial.lengthSq()<1e-6) radial.set(1,0,0); radial.normalize();
    const positions=[],indices=[],matrix=new THREE.Matrix4().fromArray(modelMatrix);
    for (const ring of rings) for (const inner of [false,true]) for (let s=0;s<=sides;s++) {
        const point=radial.clone().applyAxisAngle(axis,s*2*Math.PI/sides).multiplyScalar(Math.max(0,ring.Radius-(inner?thickness:0))).add(pivot).addScaledVector(axis,ring.Height).applyMatrix4(matrix); positions.push(point.x,point.y,-point.z);
    }
    const width=sides+1,stride=width*2;
    const quad=(a,b,c,d)=>indices.push(a,c,b,b,c,d);
    for(let r=0;r<rings.length-1;r++)for(let s=0;s<sides;s++) {const a=r*stride+s,b=(r+1)*stride+s;quad(a,a+1,b,b+1);quad(a+width+1,a+width,b+width+1,b+width);}
    for (const r of [0,rings.length-1])for(let s=0;s<sides;s++){const a=r*stride+s;quad(a+1,a,a+width+1,a+width);}
    const geometry=new THREE.BufferGeometry().setAttribute('position',new THREE.Float32BufferAttribute(positions,3));geometry.setIndex(indices);geometry.computeVertexNormals();geometry.computeBoundingBox();
    const mesh=new THREE.Mesh(geometry);mesh.userData.procedural=true;mesh.userData.fairing=true;return mesh;
}

export function buildSavedProcedural(spec, part) {
    const object = new THREE.Group(), warnings = [...(spec.warnings ?? [])]; let replacesModel = false, shapes = 0, controlPoses = 0;
    for (let i=0;i<(part.procedural ?? []).length;i++) {
        const state=part.procedural[i],definition=spec.procedural[i],rig=definition?.options[state.meshOptionIndex ?? 0];
        if (!rig) {warnings.push('Saved procedural mesh variant is unavailable.');continue;}
        const wing=buildWing(rig,state,part.mirrored,definition.modelMatrix,{...(spec.controls[i] ?? {}),...(part.controls[i] ?? {})});object.add(wing);replacesModel=true;shapes++;
    }
    if (spec.controlRig && !(part.procedural ?? []).length) {
        object.add(buildControlSurface(spec.controlRig,{...(spec.controls[0] ?? {}),...(part.controls[0] ?? {})}));
        replacesModel=true;controlPoses++;
    }
    for (let i=0;i<spec.fairings.length;i++) { const entry=spec.fairings[i],fairing=buildFairing(entry.defaults,part.fairings[i] ?? {},entry.modelMatrix);if(fairing){object.add(fairing);shapes++;} }
    return {object,replacesModel,shapes,controlPoses,warnings};
}
