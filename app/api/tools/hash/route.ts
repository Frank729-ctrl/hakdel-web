import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { hash } = await req.json()
  if (!hash) return NextResponse.json({ error: 'Hash required' }, { status: 400 })

  const [vt, mb] = await Promise.allSettled([
    fetch(`https://www.virustotal.com/api/v3/files/${encodeURIComponent(hash)}`,
      { headers: { 'x-apikey': process.env.VIRUSTOTAL_API_KEY! } }).then(r => r.json()),
    fetch('https://mb-api.abuse.ch/api/v1/', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `query=get_info&hash=${encodeURIComponent(hash)}`,
    }).then(r => r.json()),
  ])

  return NextResponse.json({
    virustotal:   vt.status === 'fulfilled' ? vt.value : null,
    malwarebazaar: mb.status === 'fulfilled' ? mb.value : null,
  })
}
