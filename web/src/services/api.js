import axios from 'axios'

const api = axios.create({
  baseURL: 'http://127.0.0.1:8000',
  withCredentials: true,        // required for Sanctum's cookie-based session auth
  withXSRFToken: true,
})

export default api
