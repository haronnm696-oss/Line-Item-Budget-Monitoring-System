import axios from 'axios';

// Laravel Sanctum SPA auth needs credentials sent on every request and the
// XSRF cookie set before the first POST. baseURL comes from .env (see .env.example).
const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

// If you switch to bearer-token auth instead of Sanctum's SPA cookie mode,
// uncomment this and store the token from the login response.
// client.interceptors.request.use((config) => {
//   const token = localStorage.getItem('lib_token');
//   if (token) config.headers.Authorization = `Bearer ${token}`;
//   return config;
// });

client.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      // Session expired or never authenticated — let the caller redirect to /login.
      window.dispatchEvent(new CustomEvent('lib:unauthorized'));
    }
    return Promise.reject(err);
  }
);

export default client;
