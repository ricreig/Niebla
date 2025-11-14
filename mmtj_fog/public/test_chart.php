<!doctype html><meta charset="utf-8">
<canvas id="c" width="600" height="200"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/luxon@3/build/global/luxon.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1/dist/chartjs-adapter-luxon.umd.min.js"></script>
<script>
const ctx = document.getElementById('c').getContext('2d');
new Chart(ctx, {
  type:'line',
  data:{ datasets:[{ label:'demo', data:[
    {x:new Date(Date.now()-3600e3), y:10},
    {x:new Date(),                   y:20},
    {x:new Date(Date.now()+3600e3), y:15},
  ]}]},
  options:{scales:{x:{type:'time'}}}
});
</script>
