import 'next-auth'

declare module 'next-auth' {
  interface Session {
    user: {
      id: string
      name?: string | null
      email?: string | null
      image?: string | null
      username?: string
      role?: string
      plan?: string
      xp?: number
      level?: number
      streakDays?: number
    }
  }
}
