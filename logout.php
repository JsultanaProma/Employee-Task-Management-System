<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            text-align: center;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 400px;
        }
        h1 {
            color: #333;
        }
        .btn {
            margin: 10px;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .btn-primary {
            background-color: #4a6cf7;
            color: white;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Confirm Logout</h1>
        <p>Are you sure you want to log out?</p>
        <button class="btn btn-primary" onclick="confirmLogout()">Yes, Log Out</button>
        <button class="btn btn-secondary" onclick="goBack()">No, Go Back</button>
    </div>

    <script>
        function confirmLogout() {
            window.location.href = 'logout_process.php';
        }
        
        function goBack() {
            window.history.back();
        }
    </script>
</body>
</html> 