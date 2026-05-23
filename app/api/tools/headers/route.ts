import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'

const SECURITY_HEADERS = [
  'content-security-policy', 'strict-transport-security', 'x-frame-options',
  'x-content-type-options', 'referrer-policy', 'permissions-policy',
  'x-xss-protection', 'cross-origin-opener-policy', 'cross-origin-embedder-policy',
]

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { url } = await req.json()
  if (!url) return NextResponse.json({ error: 'URL required' }, { status: 400 })

  try {
    const res = await fetch(url, {
      method: 'HEAD',
      redirect: 'follow',
      signal: AbortSignal.timeout(12000),
    })

    const headers: Record<string, string> = {}
    res.headers.forEach((value, key) => { headers[key.toLowerCase()] = value })

    const analysis = SECURITY_HEADERS.map((h) => ({
      header: h,
      present: h in headers,
      value: headers[h] ?? null,
    }))

    return NextResponse.json({ status: res.status, headers, analysis })
  } catch (err: any) {
    return NextResponse.json({ error: err.message ?? 'Request failed' }, { status: 500 })
  }
}
