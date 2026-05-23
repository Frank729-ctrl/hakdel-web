import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { ip } = await req.json()
  if (!ip) return NextResponse.json({ error: 'IP required' }, { status: 400 })

  const [abuse, vt, shodan] = await Promise.allSettled([
    fetch(`https://api.abuseipdb.com/api/v2/check?ipAddress=${encodeURIComponent(ip)}&maxAgeInDays=90&verbose=true`,
      { headers: { Key: process.env.ABUSEIPDB_API_KEY!, Accept: 'application/json' } }).then(r => r.json()),
    fetch(`https://www.virustotal.com/api/v3/ip_addresses/${encodeURIComponent(ip)}`,
      { headers: { 'x-apikey': process.env.VIRUSTOTAL_API_KEY! } }).then(r => r.json()),
    fetch(`https://api.shodan.io/shodan/host/${encodeURIComponent(ip)}?key=${process.env.SHODAN_API_KEY}`).then(r => r.json()),
  ])

  return NextResponse.json({
    abuseipdb:  abuse.status  === 'fulfilled' ? abuse.value  : null,
    virustotal: vt.status     === 'fulfilled' ? vt.value     : null,
    shodan:     shodan.status === 'fulfilled' ? shodan.value : null,
  })
}
