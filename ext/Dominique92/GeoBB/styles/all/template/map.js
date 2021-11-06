// Resize
//TODO ARCHI centralize in one file
$('#map').resizable({
	handles: 's,w,sw', // 2 côtés et 1 coin
	resize: function(evt, ui) {
		ui.position.left = ui.originalPosition.left; // Reste à droite de la page
	},
	stop: function(evt) {
		evt.target.map_.updateSize();
	}
});

//TODO minimize for prosilver
var geoControls = controlsCollection,
	//TODO ARCHI ==> 3 variable globales suivantes utilisée dans un autres ficher dans une fonction !!!
	titleEdit = "//TODO button comment",
	topicStyleOptions = {
		/* Editor style */
		image: new ol.style.Circle({
			radius: 4,
			fill: new ol.style.Fill({
				color: 'red'
			})
		}),
		fill: new ol.style.Fill({
			color: 'rgba(255,196,196,0.5)'
		}),
		stroke: new ol.style.Stroke({
			color: 'red',
			width: 2
		})
	},
	editStyleOptions = {
		stroke: new ol.style.Stroke({
			color: 'red',
			width: 3
		})
	};

function layerStyleOptionsFunction(properties, id, hover) {
	if (properties.icon)
		return {
			image: new ol.style.Icon({
				src: properties.icon
			})
		};

	return {
		fill: new ol.style.Fill({
			color: 'rgba(255,255,255,' + (hover ? 0.65 : 0.4) + ')'
		}),
		stroke: new ol.style.Stroke({
			color: hover ? 'red' : 'blue',
			width: hover ? 4 : 3
		})
	};
}

function geoOverlays(idColor, idExclude, noHover) { // topic_id à colorier, topic_id à exclure, hover / non
	return [new ol.layer.LayerVectorURL({
		baseUrl: 'ext/Dominique92/GeoBB/gis.php?limit=300&exclude=' + idExclude + '&',
		styleOptions: function(properties) {
			return layerStyleOptionsFunction(properties, idColor);
		},
		hoverStyleOptions: function(properties) {
			return layerStyleOptionsFunction(properties, idColor, !noHover);
		},
		label: function(properties) {
			return noHover ? null : '<a href="viewtopic.php?t=' + properties.id + '">' + properties.name + '<a>';
		},
		href: function(properties) {
			return 'viewtopic.php?t=' + properties.id;
		}
	})];
}
