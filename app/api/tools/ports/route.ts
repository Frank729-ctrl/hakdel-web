import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { target } = await req.json()
  if (!target) return NextResponse.json({ error: 'Target required' }, { status: 400 })

  try {
    const res = await fetch(`https://api.hackertarget.com/nmap/?q=${encodeURIComponent(target)}`)
    return NextResponse.json({ raw: await res.text() })
  } catch {
    return NextResponse.json({ error: 'Port scan failed' }, { status: 500 })
  }
}
