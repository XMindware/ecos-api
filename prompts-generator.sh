#!/bin/sh

# array of categories
categories=(
    "Infancia"
    "Familia"
    "Escuela"
    "Viajes"
    "Amigos"
    "Trabajo"
    "Amor"
    "Momentos difíciles"
    "Reflexiones"
    "Sueños"
)

# run through each category and generate a prompt using this command line
# php artisan ecos:generate-prompts vacaciones es --count=12 --minor
for category in "${categories[@]}"; do
    echo "Generando prompt para la categoría: $category"
    php artisan ecos:generate-prompts "$category" es --count=12 --minor
    php artisan ecos:generate-prompts "$category" es --count=12
done
