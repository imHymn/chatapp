<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reset LocalStorage</title>
</head>

<body>
    <h2>Resetting localStorage...</h2>
    <script>
        // Clear localStorage
        localStorage.clear();
        console.log("✅ localStorage cleared.");

        // Optional: Redirect back to your chat or homepage
        setTimeout(() => {
            window.location.href = "client.php"; // change to your main page
        }, 1000);
    </script>
</body>

</html>