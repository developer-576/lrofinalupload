{* views/templates/admin/admin_login.tpl *}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Admin Login - {$shop_name|escape}</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background-color: #f8f9fa;
    }
    .login-container {
      max-width: 400px;
      margin: 80px auto;
      padding: 30px;
      background: white;
      border-radius: 8px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2 class="mb-4 text-center">Admin Login</h2>
    {if isset($error) && $error}
      <div class="alert alert-danger" role="alert">{$error|escape}</div>
    {/if}
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="{$csrf_token|escape}" />
      <div class="mb-3">
        <label for="username" class="form-label">Username (Email)</label>
        <input type="email" class="form-control" id="username" name="username" required autofocus />
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required />
      </div>
      <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
