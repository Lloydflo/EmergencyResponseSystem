# Login API Connection Template

Copy-paste ready na files para sa login API connection ng application.

## Files
- `api-config.js`: central config (base URL, endpoint, timeout, headers)
- `login-api.js`: reusable function para tumawag sa login API
- `login-form-example.js`: sample integration sa login form

## Paano gamitin
1. I-include ang files sa page na may login form (sunod-sunod):
   - `api-config.js`
   - `login-api.js`
   - `login-form-example.js`
2. Palitan ang lahat ng `TODO` comments sa files.
3. I-match ang selectors/IDs sa actual HTML ng login form ninyo.

## Example script includes
```html
<script src="application/api-login-connection/api-config.js"></script>
<script src="application/api-login-connection/login-api.js"></script>
<script src="application/api-login-connection/login-form-example.js"></script>
```
