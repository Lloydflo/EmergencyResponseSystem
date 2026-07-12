/*
  Reusable login API client
  Copy-paste ready; palitan lang ang TODO sections.
*/

window.LoginApi = (function () {
  function buildLoginUrl() {
    var baseUrl = (window.API_CONFIG && window.API_CONFIG.baseUrl) || "";
    var loginPath = (window.API_CONFIG && window.API_CONFIG.loginPath) || "/login.php";

    return baseUrl.replace(/\/+$/, "") + "/" + loginPath.replace(/^\/+/, "");
  }

  function withTimeout(ms) {
    var controller = new AbortController();
    var timer = setTimeout(function () {
      controller.abort();
    }, ms);

    return {
      signal: controller.signal,
      clear: function () {
        clearTimeout(timer);
      }
    };
  }

  async function login(email, password, location) {
    // TODO: I-adjust payload keys kung iba ang expected fields ng API ninyo.
    // Example: username/password instead of email/password
    var payload = {
      email: email,
      password: password
    };

    if (location && typeof location === "object") {
      if (location.latitude !== undefined) payload.latitude = location.latitude;
      if (location.longitude !== undefined) payload.longitude = location.longitude;
      if (location.accuracy !== undefined) payload.accuracy = location.accuracy;
      if (location.speed !== undefined) payload.speed = location.speed;
      if (location.heading !== undefined) payload.heading = location.heading;
      payload.source = "responder_login";
    }

    var url = buildLoginUrl();
    var timeoutMs = (window.API_CONFIG && window.API_CONFIG.timeoutMs) || 15000;
    var timeout = withTimeout(timeoutMs);

    try {
      var response = await fetch(url, {
        method: "POST",
        headers: (window.API_CONFIG && window.API_CONFIG.defaultHeaders) || {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(payload),
        signal: timeout.signal
      });

      var data = await response.json();

      // Standardized return para iisang format lang sa UI layer.
      return {
        ok: response.ok && !!data.success,
        status: response.status,
        data: data,
        message: data.message || (response.ok ? "Login success" : "Login failed")
      };
    } catch (error) {
      var message = error && error.name === "AbortError"
        ? "Request timeout. Check API server."
        : "Network or server error.";

      return {
        ok: false,
        status: 0,
        data: null,
        message: message,
        error: error
      };
    } finally {
      timeout.clear();
    }
  }

  return {
    login: login
  };
})();
