// Resize
$('#map').resizable({
	handles: 's,w,sw', // 2 côtés et 1 coin
	resize: function(evt, ui) {
		ui.position.left = ui.originalPosition.left; // Reste à droite de la page
	},
	stop: function(evt) {
		evt.target.map_.updateSize();
	}
}); //TODO ARCHI centralize in one file

//TODO customize min prosilver / chemineur
function geoControls(options) {
	const keys = options.geoKeys;

	return controlsCollection(ol.assign({
		baseLayers: {
			'OpenTopo': layerOSM(
				'//{a-c}.tile.opentopomap.org/{z}/{x}/{y}.png',
				'<a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)'
			),
			'OSM outdoors': layerThunderforest(keys.thunderforest, 'outdoors'),
			'MRI': layerOSM(
				'//maps.refuges.info/hiking/{z}/{x}/{y}.png',
				'<a href="http://wiki.openstreetmap.org/wiki/Hiking/mri">MRI</a>'
			),
			'OSM-FR': layerOSM('//{a-c}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png'),
			'OSM contraste': layerThunderforest(keys.thunderforest, 'mobile-atlas'),
			'Hike & Bike': layerOSM( // Not on https
				'http://{a-c}.tiles.wmflabs.org/hikebike/{z}/{x}/{y}.png',
				'<a href="http://www.hikebikemap.org/">hikebikemap.org</a>'
			),
			'Autriche': layerKompass('KOMPASS Touristik'),
			'Kompas': layerKompass('KOMPASS'),
			'IGN': layerIGN(keys.ign, 'GEOGRAPHICALGRIDSYSTEMS.MAPS'),
			'IGN TOP 25': layerIGN(keys.ign, 'GEOGRAPHICALGRIDSYSTEMS.MAPS.SCAN-EXPRESS.STANDARD'),
			'IGN photos': layerIGN(keys.ign, 'ORTHOIMAGERY.ORTHOPHOTOS'),
			'IGN 1950': layerIGN(keys.ign, 'ORTHOIMAGERY.ORTHOPHOTOS.1950-1965', 'png'),
			'Cadastre': layerIGN(keys.ign, 'CADASTRALPARCELS.PARCELS', 'png'),
			'Etat major': layerIGN(keys.ign, 'GEOGRAPHICALGRIDSYSTEMS.ETATMAJOR40'),
			'Swiss': layerSwissTopo('ch.swisstopo.pixelkarte-farbe'),
			'Swiss photo': layerSwissTopo('ch.swisstopo.swissimage'),
			'Espagne': layerSpain('mapa-raster', 'MTN'),
			'Espagne photo': layerSpain('pnoa-ma', 'OI.OrthoimageCoverage'),
			'Italie': layerIGM(),
			'Angleterre': layerOS(keys.bing),
			'Google': layerGoogle('p'),
			'Google photo': layerGoogle('s'),
			'Bing photo': layerBing(keys.bing, 'Aerial'),
		}
	}, options));
}
const titleEdit = "Cliquer et déplacer un sommet pour modifier une trace\n" +
	"Cliquer sur un segment puis déplacer pour créer un sommet\n" +
	"Alt + cliquer sur un sommet pour le supprimer et applatir la ligne\n" +
	"Alt + cliquer sur un segment pour le supprimer et couper la ligne en 2\n" +
	"Ctrl + Alt + cliquer sur un segment d'une ligne pour la supprimer";
/*,
			controlDownloadGPX: {
				fileName: 'topics' //TODO name option
			}*/
/*
//TODO-CHEM complete chemineur
function postLabel(properties, feature, layer, pixel, ll4326) {
	const type = typeof layer.options_.type == 'function' ?
		layer.options_.type(properties, feature, layer, pixel, ll4326) :
		layer.options_.type || '',
		name = typeof layer.options_.name == 'function' ?
		layer.options_.name(properties, feature, layer, pixel, ll4326) :
		layer.options_.name || '';

	return ['<hr/><a title="Créer une fiche modifiable à partir du point" ' +
			'href="posting.php?mode=post', //TODO-BEST spécifique : passer en argument
			'type=' + type,
			'name=' + (name || type),
			'lon=' + Math.round(ll4326[0] * 100000) / 100000,
			'lat=' + Math.round(ll4326[0] * 100000) / 100000
		].join('&') +
		'">Créer une fiche</a>';
}
*/

function layerStyleOptionsFunction(properties, hover) {
	if (properties.icon)
		return {
			image: new ol.style.Icon({
				src: properties.icon
			})
		};

	return {
		fill: new ol.style.Fill({
			color: 'rgba(0,0,255,' + (hover ? 0.2 : 0.1) + ')'
		}),
		stroke: new ol.style.Stroke({
			color: 'blue',
			width: hover ? 3 : 1.5
		})
	};
}

//TODO factoriser
function geoOverlays(o) {
	const options = ol.assign({
		topidIdExclude: ''
	}, o);

	return [
		// chemineur.fr
		new ol.layer.LayerVectorURL({
			baseUrl: 'ext/Dominique92/GeoBB/gis.php?limit=300&forums=',
			selectorName: 'chem-cat',
			styleOptions: function(properties) {
				return layerStyleOptionsFunction(properties);
			},
			hoverStyleOptions: function(properties) {
				return layerStyleOptionsFunction(properties, true);
			},
			label: function(properties) {
				return '<a href="viewtopic.php?t=' + properties.id + '">' + properties.name + '<a>';
			},
			href: function(properties) {
				return 'viewtopic.php?t=' + properties.id;
			}
		}),

		// refuges.info
		//TODO label avec create point
		new ol.layer.LayerVectorURL({
			baseUrl: '//www.refuges.info/api/bbox?type_points=',
			selectorName: 'wri-poi',
			styleOptions: function(properties) {
				return {
					image: new ol.style.Icon({
						src: '//www.refuges.info/images/icones/' + properties.type.icone + '.png'
					})
				};
			},
			label: function(properties) { // For click on the label
				return '<a href="' + properties.lien + '">' + properties.nom + '<a>';
			},
			href: function(properties) { // For click on icon
				return properties.lien;
			}
		}),
		/*
				// camptocamp.org
				new ol.layer.LayerVectorURL({
					baseUrl: 'assets/proxy/?url=https://api.camptocamp.org/waypoints?pl=fr',
					selectorName: 'c2c-poi',
					styleOptions: function(properties) {
						return {
							image: new ol.style.Icon({
								src: '//www.refuges.info/images/icones/' + properties.type.icone + '.png'
							})
						};
					},
					label: function(properties) { // For click on the label
						return '<a href="' + properties.lien + '">' + properties.nom + '<a>';
					},
					href: function(properties) { // For click on icon
						return properties.lien;
					}
				}),

				// pyrenees-refuges.com
				new ol.layer.LayerVectorURL({
					baseUrl: 'assets/miniProxy/miniProxy.php?http://www.pyrenees-refuges.com/lib/refuges.js',
					selectorName: 'prc-poi',
					styleOptions: function(properties) {
						return {
							image: new ol.style.Icon({
								src: '//www.refuges.info/images/icones/' + properties.type.icone + '.png'
							})
						};
					},
					label: function(properties) { // For click on the label
						return '<a href="' + properties.lien + '">' + properties.nom + '<a>';
					},
					href: function(properties) { // For click on icon
						return properties.lien;
					}
				}),
		*/
		// alpages.info
		//TODO filtrer cabanes accessibles + points d'eau ? potables ?
		new ol.layer.LayerVectorURL({
			baseUrl: '//alpages.info/ext/Dominique92/GeoBB/gis.php?limit=200',
			selectorName: 'alpi',
			styleOptions: function(properties) {
				if (properties.icon)
					return {
						image: new ol.style.Icon({
							src: properties.icon
						})
					};

				const cs = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(properties.color);
				return {
					fill: new ol.style.Fill({
						color: 'rgba(' + parseInt(cs[1], 16) + ',' + parseInt(cs[2], 16) + ',' + parseInt(cs[3], 16) + ',0.5)'
					})
				};
			},
			label: function(properties) {
				return '<a href="viewtopic.php?t=' + properties.id + '">' + properties.name + '<a>';
			},
			href: function(properties) {
				return 'viewtopic.php?t=' + properties.id;
			}
		}),
		/*
		//TODO-CHEM complete chemineur
						layerOverpass({
							postLabel: postLabel
						}),
		*/
	];
}