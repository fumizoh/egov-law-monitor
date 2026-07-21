from models import PromptDocument, PromptSection


def render_section(section: PromptSection) -> str:
    """Render an input section as Markdown."""

    return f"### {section.title}\n\n{section.body}"


def render_system(document: PromptDocument) -> str:
    """Render the system section."""

    return f"## System\n\n{document.system}"


def render_role(document: PromptDocument) -> str:
    """Render the role section."""

    return f"## Role\n\n{document.role}"


def render_task(document: PromptDocument) -> str:
    """Render the task section."""

    return f"## Task\n\n{document.task}"


def render_input(document: PromptDocument) -> str:
    """Render the input sections."""

    sections = "\n\n".join(
        render_section(section)
        for section in document.sections
    )

    return f"## Input\n\n{sections}"


def render_prompt(document: PromptDocument) -> str:
    """Render a complete Markdown prompt."""

    return (
        f"# {document.title}\n\n"
        f"{render_system(document)}\n\n"
        f"{render_role(document)}\n\n"
        f"{render_task(document)}\n\n"
        f"{render_input(document)}"
    )