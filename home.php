<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dispo.Tech Intake Home</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="home">
  <main class="page">
    <section class="sheet home-sheet">
      <h1>Dispo.Tech Intake Lookup</h1>
      <p>Paste or type a SKU to open the intake sheet for review or editing.</p>
      <form class="form-grid" method="get" action="index.php">
        <div class="row">
          <label>SKU
            <input type="text" name="sku" required autofocus>
          </label>
        </div>
        <div class="actions">
          <button type="submit">Continue</button>
          <a class="button-link" href="index.php">New Intake</a>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
