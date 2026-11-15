const form = document.getElementById('form')

form.addEventListener('submit', async (e) => {
    e.preventDefault()

    const formData = new FormData(e.target)

    const type = formData.get('type')
    let resp

    if (type == 'create')
        resp = await fetch('./src/service/auth/cadastro.php', {
            method: 'POST',
            body: formData
        }).then(data => data.json())
    else
        resp = await fetch('./src/service/auth/login.php', {
            method: 'POST',
            body: formData
        }).then(data => data.json())
})