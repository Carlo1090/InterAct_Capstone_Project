import axios from 'axios'

const api = axios.create({
  withCredentials: true,
  withXSRFToken: true,
})

export default api
