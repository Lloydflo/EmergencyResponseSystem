/*
  Sample login form integration
  TODO: I-match ang selectors sa actual HTML form ninyo.
*/

document.addEventListener("DOMContentLoaded", function () {
  // TODO: Palitan kung iba ang form ID or class ninyo.
  var form = document.querySelector("#loginForm");
  if (!form || !window.LoginApi) return;

  // TODO: I-match sa actual input selectors.
  var emailInput = form.querySelector('input[name="email"]');
  var passwordInput = form.querySelector('input[name="password"]');
  var submitButton = form.querySelector('button[type="submit"]');

  // Optional message container sa UI
  // TODO: Palitan selector kung may existing error/success container kayo.
  var messageBox = document.querySelector("#loginMessage");

  function setMessage(text, isError) {
    if (!messageBox) return;
    messageBox.textContent = text;
    messageBox.style.color = isError ? "#b91c1c" : "#065f46";
  }

  function getCurrentLocation() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) {
        resolve(null);
        return;
      }

      navigator.geolocation.getCurrentPosition(function (position) {
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
          speed: position.coords.speed,
          heading: position.coords.heading
        });
      }, function () {
        resolve(null);
      }, {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0
      });
    });
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    var email = (emailInput && emailInput.value || "").trim();
    var password = (passwordInput && passwordInput.value || "").trim();

    if (!email || !password) {
      setMessage("Email and password are required.", true);
      return;
    }

    if (submitButton) submitButton.disabled = true;
    setMessage("Signing in...", false);

    var location = await getCurrentLocation();
    var result = await window.LoginApi.login(email, password, location);

    if (submitButton) submitButton.disabled = false;

    if (!result.ok) {
      setMessage(result.message || "Login failed.", true);
      return;
    }

    // TODO: I-save token/user info kung meron sa response.
    // Example:
    // localStorage.setItem("auth_token", result.data.token);
    // localStorage.setItem("user_data", JSON.stringify(result.data.user));

    // TODO: Palitan redirect page after successful login.
    setMessage("Login successful. Redirecting...", false);
    window.location.href = "index.php";
  });
});
