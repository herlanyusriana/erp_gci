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
                // Geum Cheon theme (spec 0.6)
                primary: {
                    DEFAULT: '#0B3A6E',
                    hover: '#092F59',
                    light: '#EAF2FA',
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