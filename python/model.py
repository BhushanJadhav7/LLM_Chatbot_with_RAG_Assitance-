"""
model.py — LLM Chat Model wrapper.

Wraps google/gemma-2b-it (or any causal-LM) with optional 4-bit quantisation.
Falls back to full-precision CPU inference when CUDA is unavailable so the
server can still start and handle requests in non-GPU environments.
"""

import os
import logging

import torch
from transformers import AutoTokenizer, AutoModelForCausalLM, BitsAndBytesConfig
from dotenv import load_dotenv

load_dotenv()

logger = logging.getLogger(__name__)

# Model weight cache directory — one level up, shared with api.py
CACHE_DIR = os.path.normpath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "models")
)


class ChatModel:
    """Wrapper around a HuggingFace causal-LM with optional 4-bit quantisation."""

    def __init__(self, model_id: str = "google/gemma-2b-it", device: str = "cuda"):
        access_token = os.getenv("ACCESS_TOKEN", "")
        if not access_token:
            logger.warning("ACCESS_TOKEN is not set. Gated model downloads will fail.")

        self.device = device
        cuda_available = torch.cuda.is_available()

        logger.info("Loading tokenizer for %s …", model_id)
        self.tokenizer = AutoTokenizer.from_pretrained(
            model_id,
            cache_dir=CACHE_DIR,
            token=access_token,
        )

        # Use 4-bit quantisation only when CUDA is present — bitsandbytes
        # requires GPU on Windows and most Linux builds.
        if cuda_available:
            logger.info("CUDA detected — loading model with 4-bit quantisation.")
            quantization_config = BitsAndBytesConfig(
                load_in_4bit=True,
                bnb_4bit_compute_dtype=torch.bfloat16,
            )
            self.model = AutoModelForCausalLM.from_pretrained(
                model_id,
                device_map="auto",
                quantization_config=quantization_config,
                cache_dir=CACHE_DIR,
                token=access_token,
            )
        else:
            logger.warning(
                "CUDA not available — loading model in full-precision CPU mode. "
                "Inference will be very slow."
            )
            self.model = AutoModelForCausalLM.from_pretrained(
                model_id,
                device_map="cpu",
                torch_dtype=torch.float32,
                cache_dir=CACHE_DIR,
                token=access_token,
            )
            self.device = "cpu"

        self.model.eval()
        logger.info("Model loaded successfully on device: %s", self.device)

    def generate(
        self,
        question: str,
        context: str = None,
        max_new_tokens: int = 250,
    ) -> str:
        """Generate a response for the given question, optionally with RAG context."""

        if context:
            prompt = (
                f"Using the information contained in the context, give a detailed "
                f"answer to the question.\n"
                f"Context: {context}\n"
                f"Question: {question}"
            )
        else:
            prompt = f"Give a detailed answer to the following question. Question: {question}"

        chat = [{"role": "user", "content": prompt}]
        formatted_prompt = self.tokenizer.apply_chat_template(
            chat,
            tokenize=False,
            add_generation_prompt=True,
        )

        # Use the full tokenizer call (not .encode) to get attention_mask.
        # For 4-bit bitsandbytes models with device_map="auto", we must send
        # inputs to the device the model actually lives on, not a hardcoded string.
        model_device = next(self.model.parameters()).device
        encoded = self.tokenizer(
            formatted_prompt,
            add_special_tokens=False,
            return_tensors="pt",
        ).to(model_device)

        with torch.no_grad():
            outputs = self.model.generate(
                input_ids=encoded["input_ids"],
                attention_mask=encoded["attention_mask"],
                max_new_tokens=max_new_tokens,
                do_sample=False,
                pad_token_id=self.tokenizer.eos_token_id,
            )

        response = self.tokenizer.decode(outputs[0], skip_special_tokens=False)
        # Strip the prompt prefix and clean special tokens
        response = response[len(formatted_prompt):]
        response = response.replace("<eos>", "").strip()
        return response
