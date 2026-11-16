import * as THREE from './three.module.js';

export function loginScene() {
const canvas = document.getElementById('loginThreeCanvas');
if (canvas && !window.matchMedia('(max-width: 880px)').matches) {
  try {
    const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);
    camera.position.z = 6;

    const sculpture = new THREE.Group();
    const shape = new THREE.IcosahedronGeometry(1.5, 1);
    sculpture.add(new THREE.Mesh(shape, new THREE.MeshBasicMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.035, depthWrite: false })));
    sculpture.add(new THREE.LineSegments(new THREE.EdgesGeometry(shape), new THREE.LineBasicMaterial({ color: 0x7dd3fc, transparent: true, opacity: 0.36 })));
    const orbit = new THREE.Mesh(new THREE.TorusGeometry(2.02, 0.012, 3, 96), new THREE.MeshBasicMaterial({ color: 0x5eead4, transparent: true, opacity: 0.46 }));
    orbit.rotation.x = 1.12;
    orbit.rotation.y = 0.35;
    sculpture.add(orbit);
    scene.add(sculpture);

    function resize() {
      const width = canvas.clientWidth;
      const height = canvas.clientHeight;
      if (!width || !height) return;
      camera.aspect = width / height;
      camera.updateProjectionMatrix();
      renderer.setSize(width, height, false);
      renderer.render(scene, camera);
    }
    resize();
    window.addEventListener('resize', resize, { passive: true });
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let frame = 0;
    function animate() {
      if (!reduceMotion.matches && !document.hidden) {
        sculpture.rotation.y += 0.0017;
        sculpture.rotation.x += 0.0005;
        renderer.render(scene, camera);
      }
      frame = requestAnimationFrame(animate);
    }
    if (!reduceMotion.matches) animate();
    return () => { cancelAnimationFrame(frame); window.removeEventListener('resize', resize); sculpture.traverse(item => { item.geometry?.dispose(); item.material?.dispose(); }); renderer.dispose(); };
  } catch (_) {
    canvas.remove();
  }
}

}
