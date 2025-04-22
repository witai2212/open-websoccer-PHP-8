<?php
define('BASE_FOLDER', __DIR__ .'/..');
include(BASE_FOLDER . '/admin/config/global.inc.php');
$session_id = $_GET['session'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExecuteMyJobs</title>
    <style>
        #reload-div {
            padding: 20px;
            background-color: lightblue;
            border: 1px solid #000;
            width: 300px;
            text-align: center;
        }
    </style>
</head>
<body>

<!-- Initially empty div, content will be loaded via AJAX -->
<div id="reload-div">
    Loading content...
</div>

<script>
// Function to make AJAX request and update the div
function reloadContent() {
    const div = document.getElementById("reload-div");

    const xhr = new XMLHttpRequest();
    xhr.open("GET", "reload_content.php?session=<?php echo $session_id; ?>", true); // Fetch the content from reload_content.php
    xhr.onload = function() {
        if (xhr.status === 200) {
            div.innerHTML = xhr.responseText; // Update div with the new content
        }
    };
    xhr.send();
}

// Reload the div content every 3 seconds (3000 milliseconds)
setInterval(reloadContent, 3000);

// Initial content load when the page is first loaded
reloadContent();
</script>
</body>
</html>
