import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
});

export const getCsrfCookie = async () => {
  const sanctumUrl = (process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api').replace('/api', '') + '/sanctum/csrf-cookie';
  await axios.get(sanctumUrl, {
    withCredentials: true,
  });
};

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 419) {
      // CSRF token expired, fetch a new one and retry
      await getCsrfCookie();
      return api(error.config);
    }
    if (error.response?.status === 401 && typeof window !== 'undefined') {
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;
