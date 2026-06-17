<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apalit Flood Simulation | Final Web</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            background: #0b0d10;
            color: white;
        }

        #map {
            height: calc(100vh - 80px);
            width: 100%;
            background: #0b0d10;
        }

        .header-panel {
            height: 80px;
            padding: 0 25px;
            background: #111;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #34495e;
        }

        .slider-box {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-grow: 1;
            margin: 0 40px;
        }

        input[type=range] {
            width: 100%;
            cursor: pointer;
        }

        .btn-simulate {
            background: #2980b9;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-simulate:hover {
            background: #3498db;
        }

        .barangay-label {
            background: rgba(0, 0, 0, 0.6);
            border: none;
            color: #fff;
            font-weight: bold;
            font-size: 10px;
            text-shadow: 1px 1px #000;
        }
    </style>
</head>

<body>

    <div class="header-panel">
        <h2 style="margin:0; font-size: 1.2rem; color: #3498db;">APALIT FLOOD SIM</h2>

        <div class="slider-box">
            <span>Water Level:</span>
            <input type="range" id="levelSlider" min="0" max="10" step="0.5" value="0"
                oninput="document.getElementById('lvlVal').innerText = this.value">
            <span style="min-width: 50px;"><b id="lvlVal">0</b>m</span>
        </div>

        <button id="simBtn" class="btn-simulate" onclick="runSimulation()">SIMULATE</button>
    </div>

    <div id="map"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://leaflet.github.io/Leaflet.heat/dist/leaflet-heat.js"></script>

    <script>
        const API_POLYGONS = "{{ url('api/v1/map/flood-simulation') }}";
        const API_POINTS = "{{ url('api/v1/test/flood-points') }}";

        var map = L.map('map').setView([14.945, 120.758], 13);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; CartoDB'
        }).addTo(map);

        var geojsonLayer, heatLayer;

        // Binalik sa normal na scaling (15-25px radius)
        function getDynamicRadius(zoom) {
            if (zoom >= 16) return 25;
            if (zoom >= 15) return 20;
            if (zoom >= 14) return 15;
            return 10;
        }

        async function runSimulation() {
            const level = document.getElementById('levelSlider').value;
            const btn = document.getElementById('simBtn');

            btn.disabled = true;
            btn.innerText = "PROCESSING...";

            try {
                const [polyRes, pointRes] = await Promise.all([
                    fetch(`${API_POLYGONS}?level=${level}`),
                    fetch(`${API_POINTS}?level=${level}`)
                ]);

                const polyData = await polyRes.json();
                const pointData = await pointRes.json();

                if (geojsonLayer) map.removeLayer(geojsonLayer);
                geojsonLayer = L.geoJSON(polyData, {
                    style: {
                        color: '#3498db',
                        weight: 1.5,
                        dashArray: '5, 8', // Broken line nananatili
                        fillOpacity: 0
                    },
                    onEachFeature: (feature, layer) => {
                        layer.bindTooltip(feature.properties.name, {
                            permanent: true,
                            direction: 'center',
                            className: 'barangay-label'
                        });
                    }
                }).addTo(map);

                if (heatLayer) map.removeLayer(heatLayer);
                if (pointData.data && pointData.data.length > 0) {
                    const heatPoints = pointData.data.map(p => [
                        parseFloat(p.latitude),
                        parseFloat(p.longitude),
                        0.7
                    ]);

                    heatLayer = L.heatLayer(heatPoints, {
                        radius: getDynamicRadius(map.getZoom()), // Normal radius
                        blur: 10,            // Binalik sa normal blur (Default is usually 15)
                        maxZoom: 20,
                        minOpacity: 0.1,
                        gradient: {
                            0.4: 'blue',
                            0.6: 'cyan',
                            1.0: 'white'     // Standard "hot" center look
                        }
                    }).addTo(map);
                }

            } catch (err) {
                console.error("Simulation failed:", err);
                alert("Error loading data.");
            } finally {
                btn.disabled = false;
                btn.innerText = "SIMULATE";
            }
        }

        map.on('zoomend', function () {
            if (heatLayer) {
                const newRadius = getDynamicRadius(map.getZoom());
                heatLayer.setOptions({ radius: newRadius });
            }
        });

        runSimulation();
    </script>
</body>

</html>
