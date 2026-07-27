const status = document.querySelector('#status')
const database = document.querySelector('#database')
const tableCount = document.querySelector('#tableCount')
const tables = document.querySelector('#tables')
const errorBox = document.querySelector('#error')

async function checkHealth() {
  status.className = 'status'
  status.textContent = 'Checking API'
  errorBox.hidden = true

  try {
    const response = await fetch('/api/health', { cache: 'no-store' })
    const payload = await response.json()
    if (!response.ok || !payload.success) throw new Error(payload.error?.message || 'Health check failed')

    const data = payload.data
    status.className = 'status online'
    status.textContent = 'API online'
    database.textContent = data.database
    tableCount.textContent = data.tables.length
    tables.replaceChildren(...data.tables.map((name) => {
      const item = document.createElement('span')
      item.textContent = name
      return item
    }))
  } catch (error) {
    status.className = 'status offline'
    status.textContent = 'API unavailable'
    database.textContent = 'Unavailable'
    tableCount.textContent = '-'
    tables.replaceChildren()
    errorBox.hidden = false
    errorBox.textContent = error.message
  }
}

document.querySelector('#refresh').addEventListener('click', checkHealth)
checkHealth()
