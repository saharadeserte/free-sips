
# IT490 CocktailBackend Setup

## Overview
This backend is part of our IT490 Cocktail and Drinks Capstone Project.

The backend is built using C# and ASP.NET Core 8. It runs on Ubuntu and uses systemd to manage the application.

## Requirements
- Ubuntu Linux with systemd
- .NET 8 SDK
- Git
- Tailscale access to the team's network
- RabbitMQ server managed by the messaging team

## Backend Location
milestone2/backend/CocktailBackend/

## Running the Backend Manually

Navigate to the project:

```bash
cd ~/Capstone-Group-01/milestone2/backend/CocktailBackend
dotnet run
```

The backend listens on port 5000.

## Installing the systemd Service

The service configuration is located at:

milestone2/backend/systemd/cocktail-backend.service

Before installation, update the User, WorkingDirectory, and ExecStart paths for the target Ubuntu machine.

Copy the service file:

```bash
sudo cp milestone2/backend/systemd/cocktail-backend.service /etc/systemd/system/
sudo systemctl daemon-reload
```

Enable and start the backend:

```bash
sudo systemctl enable --now cocktail-backend
```

Check the status:

```bash
systemctl status cocktail-backend --no-pager
```

## Testing the Backend

```bash
curl -i http://localhost:5000/
```

Expected response:

```json
{
  "status": "success",
  "message": "Cocktail Backend is running!"
}
```

## Starting Through the Composer-Compatible Script

```bash
bash milestone2/backend/BackendFrontend/start_be-fe.sh
```

The script uses systemd to start the backend and checks its HTTP endpoint.

For noninteractive startup, the account running this script must have permission to start the cocktail-backend service using sudo without a password.

## RabbitMQ Configuration

The backend uses the RABBITMQ_HOST environment variable to locate the team's RabbitMQ server.

RabbitMQ connectivity requires a reachable Tailscale network, a running RabbitMQ server, and properly configured authentication credentials.

## Notes

The systemd service can restart the backend after an application failure and start it when Ubuntu boots.

The host computer and Ubuntu environment must be running for the backend to remain available.

Registration, login, RabbitMQ integration, and database functionality require separate end-to-end testing.
