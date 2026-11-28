const radians = Math.PI / 180;
const coastlines = [
    [[-168,65],[-149,71],[-102,73],[-81,64],[-63,59],[-53,48],[-68,44],[-79,32],[-81,25],[-90,29],[-98,20],[-84,10],[-79,8],[-84,17],[-103,23],[-116,32],[-125,48],[-139,58],[-158,58]],
    [[-81,12],[-68,13],[-59,6],[-50,4],[-35,-6],[-39,-17],[-50,-28],[-58,-34],[-65,-55],[-75,-52],[-74,-36],[-70,-20],[-79,-7]],
    [[-73,78],[-54,84],[-20,80],[-18,70],[-40,60],[-55,63],[-64,70]],
    [[-11,36],[-8,45],[6,46],[2,51],[13,55],[5,62],[15,70],[37,72],[64,70],[83,73],[113,75],[143,71],[167,66],[178,60],[157,55],[143,50],[133,39],[124,40],[122,24],[109,20],[107,10],[103,1],[98,8],[96,19],[89,22],[81,8],[75,10],[68,22],[58,25],[50,17],[43,12],[42,28],[34,31],[28,41],[16,39],[10,42],[3,43],[0,37]],
    [[-17,35],[-5,36],[12,37],[31,32],[35,23],[43,12],[51,11],[44,-3],[39,-16],[34,-25],[26,-34],[17,-33],[11,-17],[9,0],[0,5],[-15,11],[-18,20]],
    [[112,-12],[129,-13],[139,-17],[144,-11],[154,-25],[151,-37],[143,-40],[129,-34],[115,-36],[113,-27]],
    [[46,-13],[51,-16],[48,-25],[44,-24]],
    [[96,7],[108,3],[118,8],[125,0],[139,-2],[151,-6],[135,-9],[117,-8],[106,-6],[96,-1]],
    [[131,31],[137,36],[141,44],[145,44],[141,38],[136,33]],
    [[166,-34],[176,-38],[177,-41],[169,-47],[166,-45]],
];

function onLand(lon, lat, polygon) {
    let inside = false;
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [x, y] = polygon[i];
        const [previousX, previousY] = polygon[j];
        if ((y > lat) !== (previousY > lat) && lon < x + (previousX - x) * (lat - y) / (previousY - y)) {
            inside = !inside;
        }
    }
    return inside;
}

function sphere(lon, lat) {
    const [theta, phi] = [lon * radians, lat * radians];
    return [Math.cos(phi) * Math.sin(theta), Math.sin(phi), Math.cos(phi) * Math.cos(theta)];
}

const destination = sphere(-66.16, -17.39);
const origins = [[-116, 37], [9, 45], [143, 29]].map(([lon, lat]) => sphere(lon, lat));
const land = [];
for (let lat = -56; lat <= 84; lat += 2.8) {
    const spacing = 2.8 / Math.cos(lat * radians);
    for (let lon = -177; lon < 180; lon += spacing) {
        if (coastlines.some(polygon => onLand(lon, lat, polygon))) land.push(sphere(lon, lat));
    }
}

const graticule = [
    ...Array.from({ length: 11 }, (_, i) => Array.from({ length: 91 }, (_, j) => sphere(j * 4 - 180, i * 15 - 75))),
    ...Array.from({ length: 12 }, (_, i) => Array.from({ length: 61 }, (_, j) => sphere(i * 30 - 180, j * 3 - 90))),
];
const routes = origins.map(origin => {
    const angle = Math.acos(origin.reduce((sum, value, i) => sum + value * destination[i], 0));
    return Array.from({ length: 65 }, (_, index) => {
        const t = index / 64;
        const altitude = 1 + Math.sin(Math.PI * t) * .28;
        return origin.map((value, i) => altitude * (value * Math.sin((1 - t) * angle) + destination[i] * Math.sin(t * angle)) / Math.sin(angle));
    });
});

export function mountImportGlobe(hero, motion) {
    const canvas = hero.querySelector('[data-import-globe]');
    const context = canvas.getContext('2d');
    if (!context) return;
    let frame = 0;
    let width = 0;
    let height = 0;
    let inView = true;
    let pointer = 0;

    function draw() {
        frame = 0;
        if (!width || !height) return;
        const bounds = hero.getBoundingClientRect();
        const progress = motion.matches ? 0 : Math.min(1, Math.max(0, -bounds.top / bounds.height));
        const yaw = (-49 + progress * 66 + pointer) * radians;
        const pitch = 5 * radians;
        const scale = Math.min(width, height) * .405;
        const cx = width * .5;
        const cy = height * .51;
        const [sinYaw, cosYaw, sinPitch, cosPitch] = [Math.sin(yaw), Math.cos(yaw), Math.sin(pitch), Math.cos(pitch)];

        function project([x, y, z]) {
            const depth = z * cosYaw + x * sinYaw;
            return [
                cx + scale * (x * cosYaw - z * sinYaw),
                cy - scale * (y * cosPitch - depth * sinPitch),
                y * sinPitch + depth * cosPitch,
            ];
        }

        function line(points, stroke, elevated = false) {
            context.beginPath();
            let started = false;
            for (const point of points) {
                const [x, y, z] = project(point);
                const visible = z >= 0 || (elevated && Math.hypot(x - cx, y - cy) > scale + 1);
                if (!visible) { started = false; continue; }
                if (started) context.lineTo(x, y);
                else context.moveTo(x, y);
                started = true;
            }
            context.strokeStyle = stroke;
            context.stroke();
        }

        context.clearRect(0, 0, width, height);
        const light = context.createRadialGradient(cx - scale * .38, cy - scale * .52, scale * .1, cx + scale * .12, cy + scale * .12, scale * 1.13);
        light.addColorStop(0, '#6455c5');
        light.addColorStop(.49, '#322b79');
        light.addColorStop(1, '#11112e');
        context.beginPath();
        context.arc(cx, cy, scale, 0, Math.PI * 2);
        context.fillStyle = light;
        context.fill();
        context.lineWidth = 1.15;
        context.strokeStyle = '#9389fa99';
        context.stroke();
        context.lineWidth = .65;
        graticule.forEach(points => line(points, '#b4aff42b'));

        for (const point of land) {
            const [x, y, z] = project(point);
            if (z <= 0) continue;
            context.fillStyle = z > .55 ? '#b3a8faad' : '#9393d275';
            const dot = (.65 + z * .64) * Math.max(.7, scale / 205);
            context.fillRect(x, y, dot, dot);
        }

        context.lineWidth = 1.4;
        const stroke = context.createLinearGradient(cx - scale, cy, cx + scale, cy);
        stroke.addColorStop(0, '#aaa0f975');
        stroke.addColorStop(.48, '#9bfff0');
        stroke.addColorStop(1, '#baadf9a6');
        routes.forEach(points => line(points, stroke, true));

        const [x, y, z] = project(destination);
        if (z > 0) {
            context.beginPath();
            context.arc(x, y, 9, 0, Math.PI * 2);
            context.strokeStyle = '#a1ffed9e';
            context.stroke();
            context.beginPath();
            context.arc(x, y, 3.5, 0, Math.PI * 2);
            context.fillStyle = '#a6fff1';
            context.fill();
        }
    }

    function schedule() {
        if (!frame && inView) frame = requestAnimationFrame(draw);
    }

    function measure() {
        const bounds = canvas.getBoundingClientRect();
        width = bounds.width;
        height = bounds.height;
        const resolution = Math.min(devicePixelRatio || 1, 1.75);
        canvas.width = Math.round(width * resolution);
        canvas.height = Math.round(height * resolution);
        context.setTransform(resolution, 0, 0, resolution, 0, 0);
        schedule();
    }

    new ResizeObserver(measure).observe(canvas);
    new IntersectionObserver(([entry]) => {
        inView = entry.isIntersecting;
        schedule();
    }).observe(hero);
    window.addEventListener('scroll', () => { if (!motion.matches) schedule(); }, { passive: true });
    motion.addEventListener('change', () => { pointer = 0; schedule(); });
    hero.addEventListener('pointermove', event => {
        if (motion.matches || event.pointerType !== 'mouse') return;
        const bounds = hero.getBoundingClientRect();
        pointer = (event.clientX / bounds.width - .5) * 15;
        schedule();
    });
    hero.addEventListener('pointerleave', () => { pointer = 0; schedule(); });
    measure();
}
