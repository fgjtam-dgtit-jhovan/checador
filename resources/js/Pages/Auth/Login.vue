<script setup>
import { ref } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const isPasswordVisible = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const togglePasswordVisibility = () => {
    isPasswordVisible.value = !isPasswordVisible.value;
};

</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="h-screen md:flex">
        <div
            class="relative overflow-hidden md:flex w-1/2 bg-gradient-to-tr from-gray-900 to-gray-800 i justify-around items-center hidden"
        >
            <div>
                <h1 class="text-white font-bold text-4xl font-sans">Sistema de Registro de Asistencia</h1>
                <p class="text-white mt-1">SRA</p>
            </div>

            <div class="absolute -bottom-32 -left-40 w-80 h-80 border-4 rounded-full border-opacity-30 border-t-8"></div>
            <div class="absolute -bottom-40 -left-20 w-80 h-80 border-4 rounded-full border-opacity-30 border-t-8"></div>
            <div class="absolute -top-40 -right-0 w-80 h-80 border-4 rounded-full border-opacity-30 border-t-8"></div>
            <div class="absolute -top-20 -right-20 w-80 h-80 border-4 rounded-full border-opacity-30 border-t-8"></div>
        </div>
        <div class="flex md:w-1/2 justify-center py-10 items-center bg-white">
            
            <form @submit.prevent="submit" class="w-96">
                <div v-if="status" class="border rounded border-green-500 bg-green-100 my-4 p-2 font-medium text-green-700">
                   {{ status }}
               </div>
                <h1 class="text-gray-800 font-bold text-2xl mb-4">Inicia sesión</h1>

                <InputError class="mt-2" :message="form.errors.email" />
                <div class="flex items-center border-2 py-2 px-3 rounded-2xl mb-4 container-input">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                    </svg>
                    <input 
                        class="w-full border-none bg-transparent py-2 px-4 ring-0 ml-2 focus:outline-none focus:border-none" 
                        type="email" 
                        name="email" 
                        id="email" 
                        v-model="form.email"
                        autocomplete="username"
                        required
                        autofocus
                        placeholder="Correo electrónico" 
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.password" />
                <div class="flex items-center border-2 py-2 px-3 rounded-2xl container-input">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20"
                        fill="currentColor">
                        <path fill-rule="evenodd"
                            d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z"
                            clip-rule="evenodd" />
                    </svg>
                    <input 
                        class="w-full border-none bg-transparent py-2 px-4 ring-0 ml-2 focus:outline-none focus:border-none" 
                        :type="isPasswordVisible ? 'text' : 'password'" 
                        name="password" 
                        id="password" 
                        v-model="form.password"
                        require 
                        placeholder="Contraseña" 
                    />
                    <svg 
                        class="h-5 text-gray-500 block hover:text-blue-700 cursor-pointer" 
                        fill="none" 
                        xmlns="http://www.w3.org/2000/svg" 
                        viewBox="0 0 576 512"
                        @click="togglePasswordVisibility"
                    >
                    <path
                        v-if="isPasswordVisible"
                        fill="currentColor"
                        d="M572.52 241.4C518.29 135.59 410.93 64 288 64S57.68 135.64 3.48 241.41a32.35 32.35 0 0 0 0 29.19C57.71 376.41 165.07 448 288 448s230.32-71.64 284.52-177.41a32.35 32.35 0 0 0 0-29.19zM288 400a144 144 0 1 1 144-144 143.93 143.93 0 0 1-144 144zm0-240a95.31 95.31 0 0 0-25.31 3.79 47.85 47.85 0 0 1-66.9 66.9A95.78 95.78 0 1 0 288 160z"
                    ></path>
                    <path
                        v-else
                        fill="currentColor"
                        d="M288 32C410.93 32 518.29 103.59 572.52 209.41a32.35 32.35 0 0 1 0 29.19C518.29 376.41 410.93 448 288 448S57.68 376.41 3.48 270.59a32.35 32.35 0 0 1 0-29.19C57.68 103.59 165.07 32 288 32m0 48C194.73 80 117.94 143.42 69.31 240 117.94 336.58 194.73 400 288 400s170.06-63.42 218.69-160C458.06 143.42 381.27 80 288 80z"
                    ></path>
                    </svg>
                </div>
                <button 
                    type="submit" 
                    class="block w-full bg-gray-600 mt-4 py-4 rounded-2xl text-white font-semibold mb-2 hover:bg-gray-700"
                    :class="{ 'opacity-25': form.processing }" 
                    :disabled="form.processing"
                >
                    Iniciar sesión
                </button>
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800"
                >
                    ¿Olvidó su contraseña?
                </Link>
            </form>
        </div>
    </div>
</template>

<style>
input:focus {
    --tw-ring-color: transparent !important;
}

.container-input:focus-within {
    border-color: #5d9de6;
}
</style>