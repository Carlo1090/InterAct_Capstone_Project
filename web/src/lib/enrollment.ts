import axios from 'axios'

export const NOT_ENROLLED_MESSAGE = 'You are not currently enrolled in an active OJT batch.'

export const isNotEnrolledError = (error: unknown): boolean =>
  axios.isAxiosError(error) &&
  error.response?.status === 422 &&
  error.response.data?.message === NOT_ENROLLED_MESSAGE
