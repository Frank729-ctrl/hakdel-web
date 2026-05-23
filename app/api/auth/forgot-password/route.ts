import { NextRequest, NextResponse } from 'next/server'
import crypto from 'crypto'
import { prisma } from '@/lib/db'
import { sendMail, resetPasswordTemplate } from '@/lib/mail'

export async function POST(req: NextRequest) {
  const { email } = await req.json()
  const user = await prisma.user.findUnique({ where: { email: email?.toLowerCase() } })
  if (!user) return NextResponse.json({ ok: true })

  const token = crypto.randomBytes(32).toString('hex')
  await prisma.verificationToken.deleteMany({ where: { identifier: `reset:${email}` } })
  await prisma.verificationToken.create({
    data: { identifier: `reset:${email}`, token, expires: new Date(Date.now() + 3600000) },
  })

  const url = `${process.env.NEXTAUTH_URL}/reset-password?token=${token}&email=${encodeURIComponent(email)}`
  try { await sendMail(email, 'Reset your HakDel password', resetPasswordTemplate(user.username, url)) } catch {}
  return NextResponse.json({ ok: true })
}
