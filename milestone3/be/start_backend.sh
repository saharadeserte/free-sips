#!/bin/bash



echo "Starting Cocktail Backend..."



cd "$(dirname "$0")/CocktailBackend"



export RABBITMQ_HOST="100.100.36.22"

export RABBITMQ_USER="guest"

export RABBITMQ_PASSWORD="guest"



dotnet run
