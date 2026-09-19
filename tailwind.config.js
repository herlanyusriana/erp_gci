import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Geum Cheon theme — primary diselaraskan dengan warna logo (#24106C)
                primary: {
                    DEFAULT: '#24106C',
                    hover: '#1B0C52',
                    light: '#EEEBF6',
                },
                surface: '#FFFFFF',
                background: '#F7F9FC',
                borderline: '#D9E2EC',
                ink: {
                    primary: '#17202A',
                    secondary: '#64748B',
                },
                success: '#15803D',
                warning: '#B45309',
                danger: '#B91C1C',
                info: '#0369A1',
            },
        },
    },

    plugins: [forms],
};