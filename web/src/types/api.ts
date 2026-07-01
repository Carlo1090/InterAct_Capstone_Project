export type Department = {
  id: number
  code: string
  name: string
  programs_count?: number
}

export type Program = {
  id: number
  code?: string
  name: string
  department: Department
}

export type User = {
  id: number
  name: string
  email: string
  role: 'student' | 'supervisor' | 'coordinator' | 'admin'
  is_active: boolean
  program?: Program | null
}

export type Batch = {
  id: number
  name: string
  start_date: string
  end_date: string
  required_hours: number
  working_days_per_week: number
  daily_reminder_time: string
  program: Program
  coordinator: User
}

export type PaginatedResponse<T> = {
  data: T[]
  total?: number
}
