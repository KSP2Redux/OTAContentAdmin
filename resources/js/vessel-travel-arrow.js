import * as THREE from 'three';

function corners(box) {
    return [0, 1, 2, 3, 4, 5, 6, 7].map(i => new THREE.Vector3(
        i & 1 ? box.max.x : box.min.x,
        i & 2 ? box.max.y : box.min.y,
        i & 4 ? box.max.z : box.min.z,
    ));
}

export function projectedBounds(box, camera) {
    return new THREE.Box3().setFromPoints(corners(box).map(point => point.project(camera)));
}

export function placeTravelArrow(arrow, bounds, camera, direction) {
    arrow.visible = !bounds.isEmpty();
    if (!arrow.visible) return;
    camera.updateMatrixWorld();
    const points = corners(bounds);
    if (points.some(point => point.clone().applyMatrix4(camera.matrixWorldInverse).z >= -camera.near)) {
        arrow.visible = false;
        return;
    }
    const size = bounds.getSize(new THREE.Vector3());
    const span = Math.max(size.x, size.y, size.z, 3), length = span * 0.28;
    arrow.setDirection(direction);
    arrow.setLength(length, span * 0.055, span * 0.028);
    arrow.position.copy(bounds.getCenter(new THREE.Vector3())).addScaledVector(direction, -length / 2);
    const edge = Math.max(...points.map(point => point.project(camera).x)) + 0.08;
    const right = new THREE.Vector3().setFromMatrixColumn(camera.matrixWorld, 0);
    const arrowBox = new THREE.Box3().setFromObject(arrow);
    let shift = 0;
    for (const point of corners(arrowBox)) {
        const depth = -point.clone().applyMatrix4(camera.matrixWorldInverse).z;
        // Camera-right translation preserves depth, so every arrow corner clears the vessel's projected edge.
        shift = Math.max(shift, (edge - point.project(camera).x) * depth / camera.projectionMatrix.elements[0]);
    }
    arrow.position.addScaledVector(right, shift);
    arrow.updateMatrixWorld(true);
}
