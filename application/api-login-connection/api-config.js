/* 
  API config for login connection
  TODO: Palitan ang values dito base sa actual setup ninyo.
*/

window.API_CONFIG = {
  // TODO: Palitan ng actual base URL ng API server ninyo.
  // Example local: "http://localhost/ERS/api"
  baseUrl: "http://localhost/ERS/api",

  // TODO: Kung iba ang login endpoint, palitan ito.
  loginPath: "/login.php",

  // Optional timeout para hindi mag-hang ang request
  timeoutMs: 15000,

  // TODO: Dagdagan kung may required auth headers (ex: X-API-KEY)
  defaultHeaders: {
    "Content-Type": "application/json"
  }
};
