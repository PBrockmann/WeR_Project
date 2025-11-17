
//=====================================================
function randomHSL(s) { return 'hsla(' + (Math.random() * 360) + ', ' + s + '%, 80%, 1)'; }

//=====================================================
function buildGraph(dataIn, divSelector) {

var container = document.querySelector(divSelector);
if (!container) return false;

var rect = container.getBoundingClientRect();

if (rect.width > 0 && rect.height > 0) {
   var width = rect.width;
   var height = rect.height;
} else {
   var width = document.documentElement.clientWidth;
   var height = document.documentElement.clientHeight
}

var sizeImg = 100;

var svg = d3.select(divSelector).append("svg")
  .attr("width", width)
  .attr("height", height);

var zoomContainer = svg.append("g");

d3.select('svg').call(d3.zoom().on("zoom", function (e) {
		zoomContainer.attr('transform', e.transform);
}));

var data = { nodes: dataIn }

var simulation = d3.forceSimulation()
  .force("link", d3.forceLink().distance(1000))
  .force("charge", d3.forceManyBody().strength(-800))
  .force("collide", d3.forceCollide())
  .force("x", d3.forceX())
  .force("y", d3.forceY())
  .force("center", d3.forceCenter(width / 2 -50, height / 2 -50));

var nodes = zoomContainer.append("g")
  .selectAll("node")
  .data(data.nodes)
  .enter()
  .append("g");

var circles = nodes.append("circle")
  .attr("class", "circle")
  .attr("r", sizeImg/2)
  .attr("fill", "white");

var faces = nodes.append("svg:image")
  .attr("class", "face")
  .attr("href", function(d) { return d.face + "?" + new Date().getTime(); })
  .attr("width", sizeImg)
  .attr("height", sizeImg)
  .attr("style", function(d) { return "outline: 4px solid " + randomHSL(80) });  

var texts = nodes.append("text")
  .attr("class", "mytext")
  .text(function(d) { return d.firstname + " " + d.lastname; });

var textEmail = nodes.append("text")
  .attr("class", "mytextEmail")
  .text(function(d) { return d.email; });

var tooltip = d3.select(divSelector)
  .append("tooltip");

var mouseover = function(event, d) { 
   return tooltip.html("<div style='font-weight: bold;'>Keywords: </div>" +
              "<ul>" +
	      d.keywords.sort(function (a, b) {return a.toLowerCase().localeCompare(b.toLowerCase());})	
	                .map(el => "<li>" + el).join("") + "</ul>")
			.style("visibility", "visible"); 
}
var mouseout = function(event, d) { 
   if (!nodeClicked) {
   	return tooltip.style("visibility", "hidden"); 
   }
}
var mousemove = function(event, d) {
  if (!nodeClicked) {
    var rect = container.getBoundingClientRect();

    var x = event.clientX - rect.left;  // souris relative à la div
    var y = event.clientY - rect.top;

    tooltip
      .style('left', (x + 20) + 'px')
      .style('top',  (y) + 'px');
  }
}

function ticked() {
  faces.attr("x", function(d) { return d.x; })
       .attr("y", function(d) { return d.y; });
  circles.attr("cx", function(d) { return d.x + sizeImg/2; })
         .attr("cy", function(d) { return d.y + sizeImg/2; });
  textEmail.attr("x", function(d) { return d.x + sizeImg/2; })
       .attr("y", function(d) { return d.y + sizeImg*1.2; });
  texts.attr("x", function(d) { return d.x + sizeImg/2; })
       .attr("y", function(d) { return d.y + sizeImg*1.4; });
}

simulation.nodes(data.nodes)
  .on("tick", ticked);

nodes.call(d3.drag().on("drag", dragged)
                    .on("end", dragended));

textEmail.on("mouseover", mouseover)
  .on("mousemove", mousemove)
  .on("mouseout", mouseout);

function dragged(event, d) {
  tooltip.style("visibility", "hidden");
  d.fx = event.x;
  d.fy = event.y;
  simulation.alphaTarget(0.3).restart();
}

function dragended(event, d) {
  d.fx = null;
  d.fy = null;
}

var nodeClicked = false;
nodes.on("click", function(event, d) {
   nodeClicked = !nodeClicked;
   if (nodeClicked) {
	tooltip.style("visibility", "visible"); 
   } else {
	tooltip.style("visibility", "hidden"); 
   }
   if (event.ctrlKey) {
        console.log("Mouse + Ctrl pressed");
	tooltip.style("visibility", "hidden"); 
	$(this).remove();
	console.log(d, data.nodes);
	data.nodes.splice(data.nodes.map(function(d) { return d.email; }).indexOf(d.email), 1);
	console.log("after", data.nodes);
	simulation.nodes(data.nodes);
  	simulation.alphaTarget(0.3).restart();
   }
});

tooltip.on("click", function() {
   nodeClicked = false;
   tooltip.style("visibility", "hidden"); 
});

}
