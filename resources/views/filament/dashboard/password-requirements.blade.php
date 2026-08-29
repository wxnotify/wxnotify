<div
    x-data="{
        value: '',
        get hasMinLength() { return this.value.length >= 8 },
        get hasLower() { return /[a-z]/.test(this.value) },
        get hasUpper() { return /[A-Z]/.test(this.value) },
        get hasNumber() { return /[0-9]/.test(this.value) },
        get hasSpecial() { return /[@$!%*?&]/.test(this.value) },
        rules: [
            { key: 'hasMinLength', label: 'At least 8 characters' },
            { key: 'hasLower', label: 'A lowercase letter (a-z)' },
            { key: 'hasUpper', label: 'An uppercase letter (A-Z)' },
            { key: 'hasNumber', label: 'A number (0-9)' },
            { key: 'hasSpecial', label: 'A special character (@$!%*?&)' },
        ],
        init() {
            const input = document.querySelector('[data-password-input]')

            if (! input) {
                return
            }

            this.value = input.value
            input.addEventListener('input', () => { this.value = input.value })
        },
    }"
    style="margin-top: -0.5rem; margin-bottom: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem;"
>
    <template x-for="rule in rules" :key="rule.key">
        <div
            style="display: flex; align-items: center; column-gap: 0.5rem;"
            :style="{ color: $data[rule.key] ? 'rgb(22 163 74)' : 'rgb(107 114 128)' }"
        >
            <span x-text="$data[rule.key] ? '✓' : '○'"></span>
            <span x-text="rule.label"></span>
        </div>
    </template>
</div>
