import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { domain } = await req.json()
  if (!domain) return NextResponse.json({ error: 'Domain required' }, { status: 400 })

  const [crtsh, dns] = await Promise.allSettled([
    fetch(`https://crt.sh/?q=%.${domain}&output=json`).then(r => r.json()).then((data: any[]) => {
      const subs = new Set<string>()
      data.forEach(e => e.name_value?.split('\n').forEach((n: string) => { if (n.endsWith(domain)) subs.add(n.trim()) }))
      return Array.from(subs).slice(0, 100)
    }),
    fetch(`https://api.hackertarget.com/dnslookup/?q=${domain}`).then(r => r.text()),
  ])

  return NextResponse.json({
    subdomains: crtsh.status === 'fulfilled' ? crtsh.value : [],
    dns: dns.status === 'fulfilled' ? dns.value : null,
  })
}
