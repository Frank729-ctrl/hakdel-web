import { NextAuthOptions } from 'next-auth'
import { PrismaAdapter } from '@auth/prisma-adapter'
import CredentialsProvider from 'next-auth/providers/credentials'
import GoogleProvider from 'next-auth/providers/google'
import bcrypt from 'bcryptjs'
import { prisma } from './db'

export const authOptions: NextAuthOptions = {
  adapter: PrismaAdapter(prisma) as NextAuthOptions['adapter'],
  session: { strategy: 'jwt' },
  pages: {
    signIn: '/login',
    error: '/login',
  },
  providers: [
    GoogleProvider({
      clientId: process.env.GOOGLE_CLIENT_ID!,
      clientSecret: process.env.GOOGLE_CLIENT_SECRET!,
      profile(profile) {
        return {
          id: profile.sub,
          name: profile.name,
          username: profile.email.split('@')[0].replace(/[^a-z0-9_]/gi, ''),
          email: profile.email,
          image: profile.picture,
          emailVerified: new Date(),
        }
      },
    }),
    CredentialsProvider({
      name: 'credentials',
      credentials: {
        login: { label: 'Email or Username', type: 'text' },
        password: { label: 'Password', type: 'password' },
      },
      async authorize(credentials) {
        if (!credentials?.login || !credentials?.password) return null
        const user = await prisma.user.findFirst({
          where: {
            OR: [
              { email: credentials.login.toLowerCase() },
              { username: credentials.login },
            ],
          },
        })
        if (!user || !user.password) return null
        if (!user.emailVerified) throw new Error('EMAIL_NOT_VERIFIED')
        const valid = await bcrypt.compare(credentials.password, user.password)
        if (!valid) throw new Error('INVALID_CREDENTIALS')
        await prisma.user.update({
          where: { id: user.id },
          data: { lastActive: new Date() },
        })
        return { id: user.id, email: user.email, name: user.name ?? user.username, image: user.image }
      },
    }),
  ],
  callbacks: {
    async jwt({ token, user }) {
      if (user) token.id = user.id
      return token
    },
    async session({ session, token }) {
      if (token.id && session.user) {
        session.user.id = token.id as string
        const dbUser = await prisma.user.findUnique({
          where: { id: token.id as string },
          select: { username: true, role: true, plan: true, xp: true, level: true, streakDays: true },
        })
        if (dbUser) Object.assign(session.user, dbUser)
      }
      return session
    },
    async signIn({ user, account }) {
      if (account?.provider === 'google') {
        const existing = await prisma.user.findUnique({ where: { email: user.email! } })
        if (!existing) {
          let username = user.email!.split('@')[0].replace(/[^a-z0-9_]/gi, '')
          const taken = await prisma.user.findUnique({ where: { username } })
          if (taken) username = username + Math.floor(Math.random() * 9999)
          await prisma.user.create({
            data: {
              email: user.email!,
              username,
              name: user.name,
              image: user.image,
              emailVerified: new Date(),
            },
          })
        }
      }
      return true
    },
  },
}
