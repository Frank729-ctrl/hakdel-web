import { NextRequest, NextResponse } from 'next/server'
import { prisma } from '@/lib/db'

export async function GET(req: NextRequest) {
  const token = req.nextUrl.searchParams.get('token')
  const email = req.nextUrl.searchParams.get('email')
  if (!token || !email) return NextResponse.redirect(new URL('/login?error=invalid', req.url))

  const record = await prisma.verificationToken.findFirst({
    where: { token, identifier: email },
  })
  if (!record || record.expires < new Date())
    return NextResponse.redirect(new URL('/login?error=expired', req.url))

  await prisma.user.update({ where: { email }, data: { emailVerified: new Date() } })
  await prisma.verificationToken.delete({ where: { token } })
  return NextResponse.redirect(new URL('/login?verified=1', req.url))
}
