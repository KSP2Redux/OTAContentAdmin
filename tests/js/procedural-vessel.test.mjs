import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { buildFairing, buildWing, solveRig, sampleCurve, resolvedFairingStartHeight, buildControlSurface } from '../../resources/js/procedural-vessel.js';

const identity = new THREE.Matrix4().toArray();
const node = (id, parent, p) => ({id,name:id,parent,position:p,rotation:[0,0,0,1],scale:[1,1,1]});
const rig = {
    nodes:[node('root','0',[0,0,0]),node('tip','root',[0,1,0])],constraints:[],
    sliders:[{name:'SPAN',default:0,neutral:0,limits:[],targets:[{node:'tip',mode:0,min:[0,0,0],max:[0,3,0]}]}],
    meshes:[{name:'WING_CORE',node:'root',attach:'0',positions:[0,0,1, 1,0,1, 0,1,1],indices:[0,1,2],bones:['tip'],weights:[[1],[1],[1]],joints:[[0],[0],[0]],binds:[identity]}],
};

test('saved span changes baked skinning and mirrored counterparts reflect Z',()=>{
    const small=buildWing(rig,{sliderData:{SPAN:0}},false,identity),large=buildWing(rig,{sliderData:{SPAN:1}},false,identity),mirror=buildWing(rig,{sliderData:{SPAN:1}},true,identity);
    assert.equal(small.children[0].geometry.attributes.position.getY(0),1);
    assert.equal(large.children[0].geometry.attributes.position.getY(0),4);
    assert.equal(large.children[0].geometry.attributes.position.getZ(0),-1);
    assert.equal(mirror.children[0].geometry.attributes.position.getZ(0),1);
    assert.deepEqual(rig.nodes[1].position,[0,1,0]);
});

test('saved visibility suppresses the named mesh',()=>{
    assert.equal(buildWing(rig,{visibleMeshData:{WING_CORE:false}},false,identity).children.length,0);
});

test('constraints interpolate source transforms after slider updates',()=>{
    const copy=structuredClone(rig);copy.nodes.push(node('middle','root',[0,0,0]));
    copy.constraints.push({node:'middle',mode:'position',sources:[{node:'root',weight:50},{node:'tip',weight:50}],offset:[0,0,0],axes:[true,true,true],weight:1});
    assert.equal(new THREE.Vector3().setFromMatrixPosition(solveRig(copy,{sliderData:{SPAN:1}}).world.get('middle')).y,2);
});

test('dynamic limit curve honors slopes rather than linearly interpolating',()=>{
    const curve=[{time:0,value:0,outSlope:0,weightedMode:0},{time:1,value:1,inSlope:0,weightedMode:0}];
    assert.ok(Math.abs(sampleCurve(curve,.25)-.15625)<1e-6);
    assert.equal(sampleCurve(curve,0),0);assert.equal(sampleCurve(curve,1),1);
});

test('saved shroud heights use the definition axis and model transform without a second start offset',()=>{
    const definition={LocalUpAxis:{x:0,y:-1,z:0},FairingStartHeight:40,FairingSideCount:36,FairingThickness:.05};
    const state={LocalUpAxis:{x:0,y:1,z:0},CrossSections:[{Height:2,Radius:1},{Height:4,Radius:2}]};
    const mesh=buildFairing(definition,state,new THREE.Matrix4().makeTranslation(0,3,0).toArray()),box=new THREE.Box3().setFromObject(mesh);
    assert.equal(box.min.y,-1);assert.equal(box.max.y,1);
    assert.equal(buildFairing(definition,{...state,IsDeployed:{storedValue:true}},identity),null);
    assert.equal(buildFairing(definition,{...state,FairingEnabled:{storedValue:false}},identity),null);
});

test('capped fairings close at the computed nose tip with finite geometry',()=>{
    const mesh=buildFairing({CapRadius:.375,NoseTip:.5},{CrossSections:[{Height:0,Radius:1},{Height:1,Radius:.375,IsLast:true}]},identity);
    assert.ok(Math.abs(new THREE.Box3().setFromObject(mesh).max.y-1.3)<1e-5);
    assert.ok([...mesh.geometry.attributes.position.array].every(Number.isFinite));
});

test('downward shrouds start at the mounting plate and enclose the engine',()=>{
    const definition={LocalUpAxis:{x:0,y:-1,z:0},FairingStartHeight:.5,CrossSectionHeightMax:2,BaseRadius:1};
    const start=resolvedFairingStartHeight(definition,identity);
    assert.equal(start,-.5);
    const shell=buildFairing(definition,{CrossSections:[{Height:start,Radius:1},{Height:start+definition.CrossSectionHeightMax,Radius:1}]},identity);
    const engine=new THREE.Box3(new THREE.Vector3(-.99,-1.49,-.99),new THREE.Vector3(.99,.49,.99));
    assert.ok(new THREE.Box3().setFromObject(shell).containsBox(engine));
});

test('ordinary grid fin hinges rotate rigid mesh descendants and preserve the base',()=>{
    const copy=structuredClone(rig);copy.sliders=[];copy.constraints=[];copy.nodes[1].position=[0,0,0];copy.nodes[1].name='hinge';copy.meshes[0].bones=[];copy.meshes[0].binds=[];copy.meshes[0].weights=null;copy.meshes[0].joints=null;copy.meshes[0].node='tip';copy.meshes[0].positions=[1,0,0, 2,0,0, 1,1,0];
    copy.meshes.push({...copy.meshes[0],node:'root',positions:[3,0,0, 4,0,0, 3,1,0]});
    const control={CtrlSurfacePivotTransformName:'hinge',CtrlSurfaceDeployTransformName:'default',CtrlTransformRotAxis:'Z',UseCtrlSurfaceDeployAngle:true,CtrlSurfaceDeployAngle:90,DeployAngle:{storedValue:'NaN'},Deploy:{storedValue:true}};
    const mesh=buildControlSurface(copy,control);
    assert.ok(Math.abs(mesh.children[0].geometry.attributes.position.getX(0))<1e-6);
    assert.equal(mesh.children[0].geometry.attributes.position.getY(0),1);
    assert.equal(mesh.children[1].geometry.attributes.position.getX(0),3);
});
