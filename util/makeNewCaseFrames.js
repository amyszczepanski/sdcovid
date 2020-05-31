// some stuff from the internet
// https://gist.github.com/kunnix/148eadcfde3e978a1ad1d3ec9e2a7265

// I can not deal with some aspects of JavaScript, so ad hoc it is
const mapCounter = process.argv[2];

const fs = require('fs');
const d3 = require('d3');
const jsdom = require('jsdom');
const moment = require('moment');
const { JSDOM } = jsdom;

// PHP writes this to disk once so we can keep using it 
const mapData = JSON.parse(fs.readFileSync('/var/www/html/assets/frames/zip_data.json', 'utf-8'))

// max new case rate for ZIPs with more than five cases and more than 10,000 people
const maxPer100k = mapData.max_new_per_100k;
				
// based on the case counts, define some colors
// The magic number seven (plus or minus two)
const colorScale = d3.scaleQuantize();
colorScale.domain([0, maxPer100k]);
colorScale.range(['#fef0d9', '#fdd49e', '#fdbb84', '#fc8d59', '#ef6548', '#d7301f', '#990000']);

// Use gray for ZIPs with fewer than five cases
const drabberScale = d3.scaleQuantize();
drabberScale.domain([0, maxPer100k]);
drabberScale.range(['#f7f7f7', '#d9d9d9', '#bdbdbd', '#969696', '#737373', '#525252', '#252525']);


const minDate = moment(mapData.date_span.min_date);
const maxDate = moment(mapData.date_span.max_date);
const dateExtent = parseInt(maxDate.diff(minDate.startOf("day"), 'days'));

const fakeDom = new JSDOM('<!DOCTYPE html><html><body></body></html>');

const width = 400;
const height = 300;

const body = d3.select(fakeDom.window.document).select('body');

// Make an SVG Container
const mapsvg = body.append('div').attr('class', 'container')
	.append("svg")
	.attr("version", "1.1")
	.attr("xmlns", d3.namespaces.svg)
	.attr("xmlns:xlink", d3.namespaces.xlink)
	.attr("width", width)
	.attr("height", height);

const plotData = mapData.zip_data.features;

const path = d3.geoPath()
 .projection(d3.geoMercator().fitSize([width, height], mapData.zip_data));								

// Bind data and create one path per GeoJSON feature
mapsvg.selectAll("path")
	 .data(plotData)
	 .enter()
	 .append("path")
	 .attr("d", path)
	 .style("stroke", "black")
	 .style("fill", function(d, i) {
			const value = d.properties.new_cases_per_100k[mapCounter];
			if (d.properties.case_counts[mapCounter] > 4 && d.properties.population > 10000) {
				return colorScale(value);
			} else {
				return drabberScale(value ? value : 0);
			}
	 });

const baseName = "/var/www/html/assets/frames/mapNewCaseFrame";
fs.writeFileSync(baseName + mapCounter.padStart(2, '0') + ".svg", body.select('.container').html());