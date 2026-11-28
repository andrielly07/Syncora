const form = document.getElementById('form')

if (form) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault()

        const formData = new FormData(e.target)
        const type = formData.get('type')
        const endpoint = type === 'create'
            ? '../service/auth/cadastro.php'
            : '../service/auth/login.php'

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            const result = await response.json()

            if (!response.ok || result.status >= 400) {
                throw new Error(result.message || 'Não foi possível concluir a autenticação.')
            }

            window.location.href = 'principal.php'
        } catch (error) {
            window.alert(error.message)
        }
    })
}
