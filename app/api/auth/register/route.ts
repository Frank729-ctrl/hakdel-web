import { NextRequest, NextResponse } from 'next/server'
import bcrypt from 'bcryptjs'
import crypto from 'crypto'
import { prisma } from '@/lib/db'
import { sendMail, verifyEmailTemplate } from '@/lib/mail'

export async function POST(req: NextRequest) {
  const { username, email, password } = await req.json()
  if (!username || !email || !password)
    return NextResponse.json({ error: 'All fields required.' }, { status: 400 })
  if (password.length < 8)
    return NextResponse.json({ error: 'Password must be at least 8 characters.' }, { status: 400 })

  const exists = await prisma.user.findFirst({ where: { OR: [{ email: email.toLowerCase() }, { username }] } })
  if (exists) return NextResponse.json({ error: 'Email or username already taken.' }, { status: 409 })

  const hashed = await bcrypt.hash(password, 12)
  const user = await prisma.user.create({ data: { username, email: email.toLowerCase(), password: hashed } })

  const token = crypto.randomBytes(32).toString('hex')
  await prisma.verificationToken.create({
    data: { identifier: user.email, token, expires: new Date(Date.now() + 86400000) },
  })

  const url = `${process.env.NEXTAUTH_URL}/api/auth/verify-email?token=${token}&email=${encodeURIComponent(user.email)}`
  try { await sendMail(user.email, 'Verify your HakDel account', verifyEmailTemplate(username, url)) } catch {}

  return NextResponse.json({ ok: true })
}
