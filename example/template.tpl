<!DOCTYPE html>
<html>
<head>
    <title>Smarty Bundle — примеры</title>

    {bundle input=['/css/normalize.css', '/css/main.css']}
    {bundle input=['/css/print.css'] media="print" onload="this.media='all'"}
    {bundle input=['/js/app.js'] defer=true}
    {bundle input=['/js/analytics.js'] async=true preload=true}
    {bundle content='.hidden { display: none; }'}
    {bundle content='console.log("ready");' type='js' defer=true}
    {bundle input='/css/critical.css' preload=true}
</head>
<body>
    <h1>Hello world!</h1>
</body>
</html>