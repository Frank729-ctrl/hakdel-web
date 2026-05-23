import { NextRequest, NextResponse } from 'next/server'
import bcrypt from 'bcryptjs'
import { prisma } from '@/lib/db'

export async function POST(req: NextRequest) {
  const { token, email, password } = await req.json()
  if (!token || !email || !password || password.length < 8)
    return NextResponse.json({ error: 'Invalid request.' }, { status: 400 })

  const record = await prisma.verificationToken.findFirst({ where: { token, identifier: `reset:${email}` } })
  if (!record || record.expires < new Date())
    return NextResponse.json({ error: 'Token expired or invalid.' }, { status: 400 })

  const hashed = await bcrypt.hash(password, 12)
  await prisma.user.update({ where: { email: email.toLowerCase() }, data: { password: hashed } })
  await prisma.verificationToken.delete({ where: { token } })
  return NextResponse.json({ ok: true })
}
